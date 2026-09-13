<?php

namespace App\Services\Admin;

use App\Services\PaymentReconciliationService;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Payment Operations & Monitoring — read-only aggregation over the five
 * existing payable tables (orders, afa_registrations, result_checker_orders,
 * ussd_subscriptions, wallet_topups). This class does not verify, complete,
 * reconcile, or mutate anything financial — it only reads the same
 * payment_status/status/reconciliation_* columns PaymentReconciliationService
 * itself already writes, so the numbers here can never disagree with what
 * reconciliation actually did. It never queries a payment gateway.
 *
 * The one place this class writes anything at all is the scheduler
 * heartbeat cache entry (see recordReconciliationHeartbeat()), which is
 * purely observational bookkeeping about when payments:reconcile last ran —
 * never a financial field.
 */
class PaymentHealthService
{
    public const TYPES = ['order', 'afa', 'result_checker', 'ussd', 'wallet_topup'];

    public const HEARTBEAT_CACHE_KEY = 'payments:reconcile:heartbeat';

    private const HEARTBEAT_TTL_DAYS = 30;

    public const LABELS = [
        'order' => 'Order',
        'afa' => 'AFA Registration',
        'result_checker' => 'Result Checker Order',
        'ussd' => 'USSD Subscription',
        'wallet_topup' => 'Wallet Top-up',
    ];

    /**
     * Per-type table/column map. Every payable already carries the same
     * reconciliation_attempts/last_reconciliation_at/next_reconciliation_at/
     * reconciliation_note columns (Phase 3 migration) and the same
     * payment_gateway column (Phase 3 migration), so only what genuinely
     * differs per table needs listing here.
     */
    private const SCHEMA = [
        'order' => [
            'table' => 'orders',
            'reference' => 'payment_reference',
            'amount' => 'amount_paid',
            'state' => 'payment_status',
            'pending_states' => ['unpaid', 'pending'],
            'failed_states' => ['failed'],
            'paid_states' => ['paid', 'completed'],
        ],
        'afa' => [
            'table' => 'afa_registrations',
            'reference' => 'payment_reference',
            'reference_fallback' => 'reference',
            'amount' => 'amount',
            'state' => 'payment_status',
            'pending_states' => ['pending'],
            'failed_states' => ['failed'],
            'paid_states' => ['completed'],
        ],
        'result_checker' => [
            'table' => 'result_checker_orders',
            'reference' => 'payment_reference',
            'amount' => 'total_price',
            'state' => 'status',
            'pending_states' => ['pending_payment'],
            'failed_states' => ['failed'],
            'paid_states' => ['completed', 'pending_stock', 'processing'],
        ],
        'ussd' => [
            'table' => 'ussd_subscriptions',
            'reference' => 'payment_reference',
            'amount' => 'price_paid',
            'state' => 'status',
            'pending_states' => ['pending_payment', 'paid'],
            'failed_states' => ['payment_failed'],
            'paid_states' => ['active'],
        ],
        'wallet_topup' => [
            'table' => 'wallet_topups',
            'reference' => 'reference',
            'amount' => 'amount',
            'state' => 'status',
            'pending_states' => ['initiated'],
            'failed_states' => ['failed'],
            'paid_states' => ['completed'],
        ],
    ];

    /**
     * Phase 5 introduced UssdSubscription::STATUS_PAYMENT_FAILED, but rows
     * that failed BEFORE that change only ever recorded a PAYMENT_FAILED
     * event and stayed `pending_payment` (no backfill migration was added —
     * see UssdSubscriptionPurchaseService::isTerminallyFailed(), which this
     * mirrors exactly). A ussd row counts as "confirmed failed" here if
     * EITHER is true.
     */
    private function ussdLegacyFailedSubquery(): \Closure
    {
        return function ($query) {
            $query->where('status', 'payment_failed')
                ->orWhere(function ($q) {
                    $q->where('status', 'pending_payment')
                        ->whereExists(function ($sub) {
                            $sub->selectRaw('1')
                                ->from('ussd_subscription_events')
                                ->whereColumn('ussd_subscription_events.ussd_subscription_id', 'ussd_subscriptions.id')
                                ->where('ussd_subscription_events.event', 'payment_failed');
                        });
                });
        };
    }

    /**
     * Bucketed counts for the summary cards, per payable type and totalled.
     * Every number here is a fresh COUNT() query against existing indexed
     * columns — nothing is loaded into memory, nothing is cached (the
     * summary is cheap: ~10 COUNT queries per type, all on indexed columns).
     */
    public function summary(): array
    {
        $perType = [];

        foreach (self::TYPES as $type) {
            $perType[$type] = $this->summaryForType($type);
        }

        $totals = array_fill_keys(array_keys($perType[self::TYPES[0]]), 0);
        foreach ($perType as $buckets) {
            foreach ($buckets as $key => $value) {
                $totals[$key] += $value;
            }
        }

        return ['per_type' => $perType, 'totals' => $totals];
    }

    private function summaryForType(string $type): array
    {
        $schema = self::SCHEMA[$type];
        $table = $schema['table'];
        $now = now();
        $warningMinutes = (int) config('payment_health.pending_warning_minutes', 30);
        $criticalHours = (int) config('payment_health.pending_critical_hours', 24);
        $repeatedUnknownAttempts = (int) config('payment_health.repeated_unknown_attempts', 3);

        $pendingBase = fn () => DB::table($table)->whereIn($schema['state'], $schema['pending_states']);

        $pendingUnder30 = (clone $pendingBase())->where('created_at', '>=', $now->copy()->subMinutes($warningMinutes))->count();
        $pending30To24h = (clone $pendingBase())
            ->where('created_at', '<', $now->copy()->subMinutes($warningMinutes))
            ->where('created_at', '>=', $now->copy()->subHours($criticalHours))
            ->count();
        $pendingOver24h = (clone $pendingBase())->where('created_at', '<', $now->copy()->subHours($criticalHours))->count();

        // Mirrors ReconcilePendingPayments::payableQueries()'s own $due
        // closure exactly (same MIN_AGE_MINUTES gate), so "due" here always
        // means the same thing the actual reconciler will pick up next tick.
        $reconciliationDue = (clone $pendingBase())
            ->where(function ($q) use ($now) {
                $q->whereNull('next_reconciliation_at')->orWhere('next_reconciliation_at', '<=', $now);
            })
            ->where('created_at', '<=', $now->copy()->subMinutes(PaymentReconciliationService::MIN_AGE_MINUTES))
            ->count();

        $repeatedUnknown = (clone $pendingBase())->where('reconciliation_attempts', '>=', $repeatedUnknownAttempts)->count();

        $missingGateway = DB::table($table)->whereNull('payment_gateway')->count();

        $integrityMismatch = DB::table($table)->where('reconciliation_note', 'like', 'manual_review: integrity_mismatch%')->count();

        $manualReviewRequired = DB::table($table)->where('reconciliation_note', 'like', 'manual_review%')->count();

        if ($type === 'ussd') {
            $confirmedFailed = DB::table($table)->where($this->ussdLegacyFailedSubquery())->count();
        } else {
            $confirmedFailed = DB::table($table)->whereIn($schema['state'], $schema['failed_states'])->count();
        }

        // "Reconciled successfully" = currently resolved AND it took at
        // least one automatic reconciliation attempt to get there (a normal
        // instant-checkout success never touches reconciliation_attempts).
        // KNOWN LIMITATION: PaymentReconciliationService::recordAttempt() is
        // bypassed entirely on an immediate OUTCOME_COMPLETED (see its
        // `$outcome === self::OUTCOME_COMPLETED ? $outcome : recordAttempt()`
        // pattern), so a record that resolves successfully on its very
        // first reconciliation pass never gets reconciliation_attempts
        // bumped and is indistinguishable here from an ordinary webhook
        // completion. This bucket therefore undercounts in that one case —
        // documented as a blind spot rather than silently guessed at.
        $reconciledSuccessfully = DB::table($table)
            ->whereIn($schema['state'], $schema['paid_states'])
            ->where('reconciliation_attempts', '>', 0)
            ->count();

        return [
            'pending_under_30m' => $pendingUnder30,
            'pending_30m_to_24h' => $pending30To24h,
            'pending_over_24h' => $pendingOver24h,
            'reconciliation_due' => $reconciliationDue,
            'repeated_unknown' => $repeatedUnknown,
            'manual_review_required' => $manualReviewRequired,
            'missing_gateway' => $missingGateway,
            'integrity_mismatch' => $integrityMismatch,
            'confirmed_failed' => $confirmedFailed,
            'reconciled_successfully' => $reconciledSuccessfully,
        ];
    }

    /**
     * Per-gateway operational snapshot, derived purely from existing
     * reconciliation bookkeeping — no synthetic/test transactions are ever
     * initiated to "check" a gateway (explicitly disallowed).
     */
    public function providerHealth(): array
    {
        $gateways = [];
        $blank = ['pending_count' => 0, 'unknown_count' => 0, 'reconciled_success_count' => 0, 'failed_count' => 0, 'manual_review_count' => 0];
        $repeatedUnknownAttempts = (int) config('payment_health.repeated_unknown_attempts', 3);

        $accumulate = function (iterable $rows, string $field) use (&$gateways, $blank) {
            foreach ($rows as $row) {
                $gateways[$row->payment_gateway] ??= $blank;
                $gateways[$row->payment_gateway][$field] += (int) $row->c;
            }
        };

        foreach (self::TYPES as $type) {
            $schema = self::SCHEMA[$type];
            $table = $schema['table'];

            $accumulate(
                DB::table($table)->whereNotNull('payment_gateway')
                    ->whereIn($schema['state'], $schema['pending_states'])
                    ->where('reconciliation_attempts', '<', $repeatedUnknownAttempts)
                    ->selectRaw('payment_gateway, COUNT(*) as c')->groupBy('payment_gateway')->get(),
                'pending_count'
            );

            $accumulate(
                DB::table($table)->whereNotNull('payment_gateway')
                    ->whereIn($schema['state'], $schema['pending_states'])
                    ->where('reconciliation_attempts', '>=', $repeatedUnknownAttempts)
                    ->selectRaw('payment_gateway, COUNT(*) as c')->groupBy('payment_gateway')->get(),
                'unknown_count'
            );

            $accumulate(
                DB::table($table)->whereNotNull('payment_gateway')
                    ->whereIn($schema['state'], $schema['paid_states'])
                    ->where('reconciliation_attempts', '>', 0)
                    ->selectRaw('payment_gateway, COUNT(*) as c')->groupBy('payment_gateway')->get(),
                'reconciled_success_count'
            );

            $accumulate(
                DB::table($table)->whereNotNull('payment_gateway')
                    ->where('reconciliation_note', 'like', 'manual_review%')
                    ->selectRaw('payment_gateway, COUNT(*) as c')->groupBy('payment_gateway')->get(),
                'manual_review_count'
            );

            $failedQuery = DB::table($table)->whereNotNull('payment_gateway');
            if ($type === 'ussd') {
                $failedQuery->where($this->ussdLegacyFailedSubquery());
            } else {
                $failedQuery->whereIn($schema['state'], $schema['failed_states']);
            }
            $accumulate($failedQuery->selectRaw('payment_gateway, COUNT(*) as c')->groupBy('payment_gateway')->get(), 'failed_count');
        }

        ksort($gateways);

        return $gateways;
    }

    /**
     * Read-only, paginated, filterable list of transactions needing
     * attention across all five payable types, normalized into one common
     * row shape. Built as a UNION ALL of per-table subqueries (each with its
     * own type-appropriate WHERE clauses applied BEFORE the union, so every
     * table's own indexes on payment_status/status/reconciliation_* and
     * payment_gateway are used), never as five separate full-table loads
     * merged in PHP.
     *
     * Never selects: config_data, secret keys, gateway_response, or any
     * other column that could carry a provider payload or credential — only
     * the columns explicitly listed as safe in the task brief.
     */
    public function manualReviewQueue(array $filters, ?int $perPage = null): LengthAwarePaginator
    {
        $perPage = $perPage ?? (int) config('payment_health.per_page', 25);
        $page = (int) ($filters['page'] ?? 1);

        $types = $filters['type'] ?? null;
        $typesToQuery = $types && in_array($types, self::TYPES, true) ? [$types] : self::TYPES;

        $subqueries = [];
        foreach ($typesToQuery as $type) {
            $subqueries[] = $this->buildTypeSubquery($type, $filters);
        }

        $unioned = array_shift($subqueries);
        foreach ($subqueries as $sub) {
            $unioned->unionAll($sub);
        }

        // Wrap the union so ORDER BY/LIMIT/OFFSET apply to the combined
        // result set exactly once, not per-branch.
        $wrapped = DB::table(DB::raw('('.$unioned->toSql().') as manual_review_queue'))
            ->mergeBindings($unioned)
            ->orderByDesc('created_at');

        $total = (clone $wrapped)->count();
        $rows = $wrapped->forPage($page, $perPage)->get();

        return new LengthAwarePaginator($rows, $total, $perPage, $page, [
            'path' => $filters['path'] ?? request()->url(),
            'query' => $filters['query'] ?? [],
        ]);
    }

    private function buildTypeSubquery(string $type, array $filters): Builder
    {
        $schema = self::SCHEMA[$type];
        $table = $schema['table'];
        $referenceExpr = isset($schema['reference_fallback'])
            ? "COALESCE({$schema['reference']}, {$schema['reference_fallback']})"
            : $schema['reference'];

        $query = DB::table($table)->select([
            DB::raw("'{$type}' as payable_type"),
            'id',
            DB::raw("{$referenceExpr} as reference"),
            DB::raw('payment_gateway as gateway'),
            DB::raw("{$schema['amount']} as amount"),
            DB::raw("{$schema['state']} as payment_state"),
            'created_at',
            'reconciliation_attempts',
            'last_reconciliation_at',
            'next_reconciliation_at',
            'reconciliation_note',
        ]);

        // Default "needs attention": still unresolved, OR has ANY
        // reconciliation history worth a human looking at (a note is only
        // ever written by PaymentReconciliationService, ANd never for a
        // clean first-try success). A resolved row with no note at all
        // (the overwhelming majority — normal instant checkouts) never
        // appears here.
        $state = $filters['state'] ?? null;
        if ($state === 'pending') {
            $query->whereIn($schema['state'], $schema['pending_states']);
        } elseif ($state === 'failed') {
            if ($type === 'ussd') {
                $query->where($this->ussdLegacyFailedSubquery());
            } else {
                $query->whereIn($schema['state'], $schema['failed_states']);
            }
        } elseif ($state === 'manual_review') {
            $query->where('reconciliation_note', 'like', 'manual_review%');
        } elseif ($state === 'missing_gateway') {
            $query->whereNull('payment_gateway');
        } else {
            $query->where(function ($q) use ($schema) {
                $q->whereIn($schema['state'], $schema['pending_states'])
                    ->orWhereNotNull('reconciliation_note');
            });
        }

        if (! empty($filters['gateway'])) {
            $query->where('payment_gateway', $filters['gateway']);
        }

        if (! empty($filters['reason'])) {
            $query->where('reconciliation_note', 'like', '%'.$filters['reason'].'%');
        }

        if (! empty($filters['reference'])) {
            $ref = $filters['reference'];
            $query->where(function ($q) use ($schema, $ref) {
                $q->where($schema['reference'], 'like', '%'.$ref.'%');
                if (isset($schema['reference_fallback'])) {
                    $q->orWhere($schema['reference_fallback'], 'like', '%'.$ref.'%');
                }
            });
        }

        if (! empty($filters['age'])) {
            $now = now();
            $warningMinutes = (int) config('payment_health.pending_warning_minutes', 30);
            $criticalHours = (int) config('payment_health.pending_critical_hours', 24);

            match ($filters['age']) {
                'under_30m' => $query->where('created_at', '>=', $now->copy()->subMinutes($warningMinutes)),
                '30m_to_24h' => $query->where('created_at', '<', $now->copy()->subMinutes($warningMinutes))
                    ->where('created_at', '>=', $now->copy()->subHours($criticalHours)),
                'over_24h' => $query->where('created_at', '<', $now->copy()->subHours($criticalHours)),
                default => null,
            };
        }

        return $query;
    }

    /**
     * Reads the last payments:reconcile heartbeat (see
     * recordReconciliationHeartbeat(), written by the command itself) and
     * classifies it OK/WARNING/CRITICAL by staleness.
     */
    public function schedulerHealth(): array
    {
        $heartbeat = Cache::get(self::HEARTBEAT_CACHE_KEY);
        $criticalMinutes = (int) config('payment_health.stale_reconciliation_critical_minutes', 15);

        if (! is_array($heartbeat) || empty($heartbeat['finished_at'])) {
            return [
                'known' => false,
                'status' => 'critical',
                'message' => 'No payments:reconcile run has ever been recorded.',
            ];
        }

        $finishedAt = \Illuminate\Support\Carbon::parse($heartbeat['finished_at']);
        $ageMinutes = $finishedAt->diffInMinutes(now());

        $status = 'ok';
        if ($heartbeat['status'] === 'crashed') {
            $status = 'critical';
        } elseif ($ageMinutes > $criticalMinutes) {
            $status = 'critical';
        } elseif ($ageMinutes > $criticalMinutes / 2) {
            $status = 'warning';
        }

        return array_merge($heartbeat, [
            'known' => true,
            'status' => $status,
            'age_minutes' => $ageMinutes,
        ]);
    }

    /**
     * Called once, at the end of ReconcilePendingPayments::handle() (in a
     * finally block, so a run that crashes still leaves a truthful record).
     * A single atomic Cache::put — never a read-modify-write — so two
     * overlapping runs (the scheduler's own withoutOverlapping() already
     * prevents this for the scheduled path, but a manually-triggered CLI
     * run could still coincide) can never corrupt this: the last one to
     * finish simply wins, which is the correct semantics for "when did
     * reconciliation last run".
     */
    public function recordReconciliationHeartbeat(array $data): void
    {
        Cache::put(self::HEARTBEAT_CACHE_KEY, array_merge($data, [
            'finished_at' => now()->toIso8601String(),
        ]), now()->addDays(self::HEARTBEAT_TTL_DAYS));
    }

    /**
     * Failed-job and external-fulfillment backlog visibility, using only
     * what Laravel's own database queue driver already stores — no Horizon,
     * no new job-tracking infrastructure.
     */
    public function queueHealth(): array
    {
        $failedJobs = DB::table('failed_jobs')
            ->select(['id', 'queue', 'payload', 'exception', 'failed_at'])
            ->orderByDesc('failed_at')
            ->limit(20)
            ->get()
            ->map(function ($row) {
                $payload = json_decode($row->payload, true);
                $job = $payload['displayName'] ?? 'unknown';

                return [
                    'id' => $row->id,
                    'queue' => $row->queue,
                    'job' => $job,
                    // First line only — never the full stack trace (may
                    // reference file paths/args from the job's own state).
                    'exception_summary' => strtok((string) $row->exception, "\n"),
                    'failed_at' => $row->failed_at,
                ];
            });

        $failedJobsTotal = DB::table('failed_jobs')->count();
        $queuedJobsTotal = DB::table('jobs')->count();

        $paidNotFulfilled = DB::table('orders')
            ->whereIn('payment_status', ['paid', 'completed'])
            ->where(function ($q) {
                $q->whereNull('external_fulfillment_status')
                    ->orWhereIn('external_fulfillment_status', ['pending', 'queued', 'processing']);
            })
            ->count();

        $fulfillmentFailed = DB::table('orders')
            ->where('external_fulfillment_status', 'failed')
            ->count();

        return [
            'failed_jobs_total' => $failedJobsTotal,
            'failed_jobs_recent' => $failedJobs,
            'queued_jobs_total' => $queuedJobsTotal,
            'orders_awaiting_fulfillment' => $paidNotFulfilled,
            'orders_fulfillment_failed' => $fulfillmentFailed,
        ];
    }

    /**
     * Pure read/compute — never mutates a financial record because a
     * condition fired here. Thresholds come from config/payment_health.php.
     */
    public function alerts(array $summary, array $schedulerHealth, array $queueHealth): array
    {
        $alerts = [];
        $totals = $summary['totals'];
        $manualReviewBacklogCritical = (int) config('payment_health.manual_review_backlog_critical', 20);
        $fulfillmentBacklogWarning = (int) config('payment_health.fulfillment_backlog_warning', 20);

        if ($schedulerHealth['status'] === 'critical') {
            $alerts[] = [
                'level' => 'critical',
                'message' => $schedulerHealth['known']
                    ? 'payments:reconcile has not completed successfully in over '.config('payment_health.stale_reconciliation_critical_minutes', 15).' minutes.'
                    : 'payments:reconcile has never recorded a successful run.',
            ];
        } elseif ($schedulerHealth['status'] === 'warning') {
            $alerts[] = ['level' => 'warning', 'message' => 'payments:reconcile is running behind schedule.'];
        }

        if ($totals['integrity_mismatch'] > 0) {
            $alerts[] = ['level' => 'critical', 'message' => "{$totals['integrity_mismatch']} record(s) have a verified-amount integrity mismatch and require manual review."];
        }

        $manualReviewTotal = $totals['manual_review_required'];
        if ($manualReviewTotal >= $manualReviewBacklogCritical) {
            $alerts[] = ['level' => 'critical', 'message' => "Manual review backlog is large ({$manualReviewTotal} records)."];
        }

        if ($totals['pending_over_24h'] > 0) {
            $alerts[] = ['level' => 'warning', 'message' => "{$totals['pending_over_24h']} record(s) have been pending for over 24 hours."];
        } elseif ($totals['pending_30m_to_24h'] > 0) {
            $alerts[] = ['level' => 'warning', 'message' => "{$totals['pending_30m_to_24h']} record(s) have been pending for over 30 minutes."];
        }

        if ($totals['repeated_unknown'] > 0) {
            $alerts[] = ['level' => 'warning', 'message' => "{$totals['repeated_unknown']} record(s) have repeatedly verified as UNKNOWN/pending."];
        }

        if ($queueHealth['orders_awaiting_fulfillment'] >= $fulfillmentBacklogWarning) {
            $alerts[] = ['level' => 'warning', 'message' => "{$queueHealth['orders_awaiting_fulfillment']} paid order(s) awaiting external fulfillment."];
        }

        if ($queueHealth['failed_jobs_total'] > 0) {
            $alerts[] = ['level' => 'warning', 'message' => "{$queueHealth['failed_jobs_total']} job(s) in the failed-jobs table."];
        }

        return $alerts;
    }
}
