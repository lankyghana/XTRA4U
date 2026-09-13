<?php

namespace App\Console\Commands;

use App\Models\AfaRegistration;
use App\Models\Order;
use App\Models\ResultCheckerOrder;
use App\Models\UssdSubscription;
use App\Models\WalletTopup;
use App\Services\Admin\PaymentHealthService;
use App\Services\PaymentReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Phase 3 payment reconciliation hardening — orchestration only.
 *
 * This command's entire job is: select records that are due, hand each one
 * to PaymentReconciliationService::reconcile(), and report counts. It
 * contains zero provider-specific or gateway-verification logic — that all
 * lives in the service, which is also what `payments:cleanup` calls, so
 * there is exactly one implementation of "what does SUCCESS/FAILED/PENDING/
 * UNKNOWN mean and what do we do about it".
 */
class ReconcilePendingPayments extends Command
{
    protected $signature = 'payments:reconcile
                            {--limit=200 : Maximum candidate records to process per run, per payable type}
                            {--type= : Restrict to one payable type: order|afa|result_checker|ussd|wallet_topup}
                            {--reference= : Reconcile a single payable by its stored payment reference (manual mode). Always uses that record\'s own stored gateway — there is no way to override or select a gateway.}';

    protected $description = 'Query each pending payment\'s original gateway for its true status and safely complete/cancel it — the automatic fallback for payments a browser callback and webhook both missed';

    public function __construct(
        private PaymentReconciliationService $reconciliationService,
        private PaymentHealthService $paymentHealthService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $reference = $this->option('reference');
        if (is_string($reference) && $reference !== '') {
            return $this->reconcileByReference($reference);
        }

        $limit = max(1, (int) $this->option('limit'));
        $onlyType = $this->option('type');

        $totals = [];
        $startedAt = now();
        $status = 'crashed';

        // The heartbeat is purely observational bookkeeping about *when*
        // this command last ran and what it found — never a financial
        // field, and never read by PaymentReconciliationService itself.
        // Recorded in `finally` so a run that throws still leaves a
        // truthful (crashed) record instead of silently going stale.
        try {
            foreach ($this->payableQueries($limit) as $type => $query) {
                if ($onlyType && $onlyType !== $type) {
                    continue;
                }

                $totals[$type] = $this->processBatch($type, $query);
            }

            foreach ($totals as $type => $stats) {
                $this->reportStats($type, $stats);
            }

            Log::info('payments:reconcile run completed', ['totals' => $totals]);

            $this->info('Reconciliation run complete.');
            $status = 'ok';

            return self::SUCCESS;
        } finally {
            $this->paymentHealthService->recordReconciliationHeartbeat([
                'status' => $status,
                'started_at' => $startedAt->toIso8601String(),
                'duration_seconds' => $startedAt->diffInSeconds(now()),
                'records_examined' => array_sum(array_map(fn (array $s) => array_sum($s), $totals)),
                'totals' => $totals,
            ]);
        }
    }

    /**
     * Manual mode: find the payable locally by its stored reference across
     * every supported table, then reconcile it exactly like the automatic
     * path — same service call, same gateway (the record's own), no way to
     * force success and no way to select which provider gets queried.
     */
    private function reconcileByReference(string $reference): int
    {
        $payable = Order::where('payment_reference', $reference)->first()
            ?? AfaRegistration::query()->where('payment_reference', $reference)->orWhere('reference', $reference)->first()
            ?? ResultCheckerOrder::where('payment_reference', $reference)->first()
            ?? UssdSubscription::where('payment_reference', $reference)->first()
            ?? WalletTopup::where('reference', $reference)->first();

        if (! $payable) {
            $this->error("No payable found locally for reference '{$reference}'.");

            return self::FAILURE;
        }

        $type = class_basename($payable);
        $gatewayLabel = $payable->payment_gateway ?? 'none recorded';
        $this->info("Found {$type} #{$payable->id} — reconciling against its own stored gateway ({$gatewayLabel})...");

        try {
            $outcome = $this->reconciliationService->reconcile($payable);
        } catch (\Throwable $e) {
            Log::error('payments:reconcile --reference — unhandled exception', [
                'payable_type' => $type,
                'payable_id' => $payable->id,
                'error' => $e->getMessage(),
            ]);
            $this->error("Reconciliation threw an exception: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Outcome: {$outcome}");

        return self::SUCCESS;
    }

    /**
     * @return array<string, Builder>
     */
    private function payableQueries(int $limit): array
    {
        $due = fn (Builder $query) => $query->where(function (Builder $q) {
            $q->whereNull('next_reconciliation_at')
                ->orWhere('next_reconciliation_at', '<=', now());
        })->where('created_at', '<=', now()->subMinutes(PaymentReconciliationService::MIN_AGE_MINUTES));

        return [
            'order' => $due(Order::whereIn('payment_status', ['pending', 'unpaid']))
                ->orderBy('id')->limit($limit),

            'afa' => $due(AfaRegistration::where('payment_status', 'pending'))
                ->orderBy('id')->limit($limit),

            'result_checker' => $due(ResultCheckerOrder::where('status', 'pending_payment'))
                ->orderBy('id')->limit($limit),

            'ussd' => $due(UssdSubscription::whereIn('status', [
                UssdSubscription::STATUS_PENDING_PAYMENT,
                UssdSubscription::STATUS_PAID,
            ]))->orderBy('id')->limit($limit),

            'wallet_topup' => $due(WalletTopup::where('status', 'initiated'))
                ->orderBy('id')->limit($limit),
        ];
    }

    private function processBatch(string $type, Builder $query): array
    {
        $stats = [
            'completed' => 0,
            'cancelled' => 0,
            'left_pending' => 0,
            'no_gateway' => 0,
            'integrity_mismatch' => 0,
            'manual_review' => 0,
            'exception' => 0,
        ];

        $query->get()->each(function ($payable) use (&$stats, $type) {
            // Phase 5 fix: an uncaught exception from a single payable (a
            // gateway client throwing something other than the usual
            // try/catch-wrapped array response, a DB error mid-completion,
            // etc.) used to propagate out of this closure and abort the
            // ENTIRE batch — every payable queued after it, on every
            // gateway, silently never got reconciled that run. Never log
            // the exception's raw provider payload (may carry secrets);
            // only the message and the payable's own id/type.
            try {
                $outcome = $this->reconciliationService->reconcile($payable);
                $stats[$outcome] = ($stats[$outcome] ?? 0) + 1;
            } catch (\Throwable $e) {
                $stats['exception']++;
                Log::error('payments:reconcile — unhandled exception reconciling one payable, continuing with the rest of the batch', [
                    'payable_type' => $type,
                    'payable_id' => $payable->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        return $stats;
    }

    private function reportStats(string $type, array $stats): void
    {
        $this->info(sprintf(
            '%s — completed: %d, cancelled: %d, left pending: %d, no gateway: %d, integrity mismatch: %d, parked for manual review: %d, exceptions: %d',
            $type,
            $stats['completed'],
            $stats['cancelled'],
            $stats['left_pending'],
            $stats['no_gateway'],
            $stats['integrity_mismatch'],
            $stats['manual_review'],
            $stats['exception'] ?? 0,
        ));
    }
}
