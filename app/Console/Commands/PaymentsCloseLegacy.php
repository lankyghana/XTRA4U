<?php

namespace App\Console\Commands;

use App\Models\AfaRegistration;
use App\Models\Order;
use App\Models\ResultCheckerOrder;
use App\Models\UssdSubscription;
use App\Models\WalletTopup;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Production legacy-order cleanup.
 *
 * Thousands of historical pending/incomplete payment records predate the
 * Phase 3 reconciliation hardening. Many of them were, in reality, already
 * delivered through an external provider long ago — but their local
 * payment/order/fulfillment state was never finalized, so they still match
 * the exact same `payment_status`/`status` values that
 * PaymentReconciliationService, `payments:reconcile` and `payments:cleanup`
 * treat as "still pending" (see PaymentHealthService::SCHEMA, which this
 * command's eligibility rules deliberately mirror). Left alone, that means
 * they keep being handed to PaymentReconciliationService, which can — and,
 * in production, did — discover a "successful" payment on an already
 * long-delivered order and dispatch external fulfillment a second time.
 *
 * This command is a pure DATABASE STATE CLEANUP. It never talks to a
 * payment gateway or an external fulfillment provider, never queues
 * fulfillment, never touches a wallet balance, and never deletes a
 * financial/order record. Its only job is to move confirmed legacy records
 * out of the `payment_status`/`status` values that make them eligible for
 * automatic reconciliation or fulfillment, using the exact same terminal
 * values PaymentReconciliationService's own FAILED branches already use
 * (see reconcileOrder()/reconcileAfaRegistration()/etc.) — no new status
 * value is invented anywhere in this command.
 *
 * Eligibility is deliberately narrow and re-verified under a row lock
 * immediately before every write (see the close*() methods below), so it is
 * structurally impossible for this command to ever turn a successful
 * payment into a failed one: a record already in a paid state is never a
 * candidate for closure in the first place, at selection time or at write
 * time.
 *
 * Paid-but-not-yet-fulfilled records (the population `payments:cleanup`'s
 * own "PAID BUT UNFULFILLED WARNING" describes) are always classified as
 * "unsafe/ambiguous" and reported separately — this command never
 * auto-closes them, because there is no positive proof in this database
 * that they were actually delivered.
 */
class PaymentsCloseLegacy extends Command
{
    protected $signature = 'payments:close-legacy
                            {--before= : Cutoff date (YYYY-MM-DD). Only records created strictly before this date are ever considered. Required.}
                            {--execute : Actually write the closure. Without this flag the command only reports what it would do (dry run — the default).}
                            {--type= : Restrict to one payable type: order|afa|result_checker|ussd|wallet_topup}
                            {--chunk=200 : Number of matching records processed per batch/transaction.}';

    protected $description = 'Administratively close confirmed legacy pending payment records (older than --before) out of automatic reconciliation and external-fulfillment eligibility — never contacts a gateway or fulfillment provider, never deletes anything.';

    public const TYPES = ['order', 'afa', 'result_checker', 'ussd', 'wallet_topup'];

    public function handle(): int
    {
        $beforeOption = $this->option('before');
        if (! is_string($beforeOption) || trim($beforeOption) === '') {
            $this->error('--before=YYYY-MM-DD is required.');

            return self::FAILURE;
        }

        try {
            $cutoff = Carbon::parse($beforeOption)->startOfDay();
        } catch (\Throwable $e) {
            $this->error("Invalid --before date '{$beforeOption}': {$e->getMessage()}");

            return self::FAILURE;
        }

        $onlyType = $this->option('type');
        if ($onlyType !== null && ! in_array($onlyType, self::TYPES, true)) {
            $this->error('--type must be one of: '.implode('|', self::TYPES));

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $chunk = max(1, (int) $this->option('chunk'));

        $this->info($execute
            ? "EXECUTING closure for legacy records created before {$cutoff->toDateString()}..."
            : "DRY RUN (default) — zero database writes will be made. Cutoff: created before {$cutoff->toDateString()}.");
        $this->newLine();

        $handlers = [
            'order' => fn () => $this->processOrders($cutoff, $execute, $chunk),
            'afa' => fn () => $this->processAfa($cutoff, $execute, $chunk),
            'result_checker' => fn () => $this->processResultChecker($cutoff, $execute, $chunk),
            'ussd' => fn () => $this->processUssd($cutoff, $execute, $chunk),
            'wallet_topup' => fn () => $this->processWalletTopups($cutoff, $execute, $chunk),
        ];

        $results = [];
        foreach ($handlers as $type => $handler) {
            if ($onlyType && $onlyType !== $type) {
                continue;
            }

            $results[$type] = $handler();
        }

        $this->renderMainTable($results);
        $this->renderClassificationTable($results);

        if ($execute) {
            $this->newLine();
            foreach ($results as $r) {
                $this->info("{$r['label']}: closed {$r['closed']} record(s).");
            }
        } else {
            $this->newLine();
            $this->warn('This was a DRY RUN. No records were modified. Re-run with --execute to apply.');
        }

        Log::info('payments:close-legacy run completed', [
            'before' => $cutoff->toDateString(),
            'execute' => $execute,
            'type' => $onlyType,
            'results' => $results,
        ]);

        return self::SUCCESS;
    }

    // -----------------------------------------------------------------
    // Order
    // -----------------------------------------------------------------

    private function processOrders(Carbon $cutoff, bool $execute, int $chunk): array
    {
        // Mirrors PaymentHealthService::SCHEMA['order'] exactly — these are
        // the values that make an order reconciliation-eligible today.
        // Orders have no ambiguous pseudo-paid pending value (unlike USSD),
        // so every eligible record is also safely closable.
        $pendingStates = ['unpaid', 'pending'];
        $failedStates = ['failed'];
        $paidStates = ['paid', 'completed'];

        $base = fn () => Order::query()->where('created_at', '<', $cutoff);
        $closable = fn () => (clone $base())->whereIn('payment_status', $pendingStates);

        $eligible = (clone $closable())->count();

        $legacyManualReview = (clone $closable())->where('reconciliation_note', 'like', 'manual_review%')->count();
        $legacyPending = $eligible - $legacyManualReview;

        $alreadyTerminal = (clone $base())->whereIn('payment_status', $failedStates)->count();

        $paidBase = fn () => (clone $base())->whereIn('payment_status', $paidStates);
        $paidTotal = (clone $paidBase())->count();
        // Positive proof of delivery: the external fulfillment pipeline
        // itself recorded success, or a delivery timestamp is on file.
        $alreadyFulfilled = (clone $paidBase())->where(function (Builder $q) {
            $q->where('external_fulfillment_status', 'succeeded')
                ->orWhereNotNull('external_fulfillment_delivered_at');
        })->count();
        $unsafeAmbiguous = $paidTotal - $alreadyFulfilled;

        $closed = $execute ? $this->closeOrders($closable(), $chunk, $cutoff) : 0;

        return [
            'label' => 'Orders',
            'eligible' => $eligible,
            'would_close' => $eligible,
            'skipped' => 0,
            'legacy_pending' => $legacyPending,
            'legacy_manual_review' => $legacyManualReview,
            'already_terminal' => $alreadyTerminal,
            'paid' => $paidTotal,
            'already_fulfilled' => $alreadyFulfilled,
            'unsafe_ambiguous' => $unsafeAmbiguous,
            'closed' => $closed,
        ];
    }

    private function closeOrders(Builder $query, int $chunk, Carbon $cutoff): int
    {
        $closed = 0;
        $note = $this->closureNote($cutoff);

        $query->select('id')->orderBy('id')->chunkById($chunk, function ($rows) use (&$closed, $note, $cutoff) {
            foreach ($rows as $row) {
                DB::transaction(function () use ($row, $note, $cutoff, &$closed) {
                    $locked = Order::whereKey($row->id)->lockForUpdate()->first();

                    // Re-verify eligibility under lock. A record that has
                    // since become paid (or was already closed by an
                    // earlier/concurrent run) is left exactly as-is.
                    if (! $locked
                        || ! in_array($locked->payment_status, ['unpaid', 'pending'], true)
                        || ($locked->created_at && $locked->created_at->gte($cutoff))
                    ) {
                        return;
                    }

                    Order::whereKey($locked->id)->update([
                        'payment_status' => 'failed',
                        'status' => 'Failed',
                        'reconciliation_note' => $note,
                        'next_reconciliation_at' => now()->addYears(10),
                    ]);

                    $closed++;
                });
            }
        });

        return $closed;
    }

    // -----------------------------------------------------------------
    // AFA registration
    // -----------------------------------------------------------------

    private function processAfa(Carbon $cutoff, bool $execute, int $chunk): array
    {
        $base = fn () => AfaRegistration::query()->where('created_at', '<', $cutoff);
        $closable = fn () => (clone $base())->where('payment_status', AfaRegistration::PAYMENT_PENDING);

        $eligible = (clone $closable())->count();

        $legacyManualReview = (clone $closable())->where('reconciliation_note', 'like', 'manual_review%')->count();
        $legacyPending = $eligible - $legacyManualReview;

        $alreadyTerminal = (clone $base())->where('payment_status', AfaRegistration::PAYMENT_FAILED)->count();

        // A completed AFA registration IS the fulfilled deliverable — there
        // is no separate "paid but not yet delivered" state for this
        // payable, so the whole paid bucket is already-fulfilled.
        $paidTotal = (clone $base())->where('payment_status', AfaRegistration::PAYMENT_COMPLETED)->count();

        $closed = $execute ? $this->closeAfa($closable(), $chunk, $cutoff) : 0;

        return [
            'label' => 'AFA',
            'eligible' => $eligible,
            'would_close' => $eligible,
            'skipped' => 0,
            'legacy_pending' => $legacyPending,
            'legacy_manual_review' => $legacyManualReview,
            'already_terminal' => $alreadyTerminal,
            'paid' => $paidTotal,
            'already_fulfilled' => $paidTotal,
            'unsafe_ambiguous' => 0,
            'closed' => $closed,
        ];
    }

    private function closeAfa(Builder $query, int $chunk, Carbon $cutoff): int
    {
        $closed = 0;
        $note = $this->closureNote($cutoff);

        $query->select('id')->orderBy('id')->chunkById($chunk, function ($rows) use (&$closed, $note, $cutoff) {
            foreach ($rows as $row) {
                DB::transaction(function () use ($row, $note, $cutoff, &$closed) {
                    $locked = AfaRegistration::whereKey($row->id)->lockForUpdate()->first();

                    if (! $locked
                        || $locked->payment_status !== AfaRegistration::PAYMENT_PENDING
                        || ($locked->created_at && $locked->created_at->gte($cutoff))
                    ) {
                        return;
                    }

                    AfaRegistration::whereKey($locked->id)->update([
                        'payment_status' => AfaRegistration::PAYMENT_FAILED,
                        'status' => AfaRegistration::STATUS_CANCELLED,
                        'admin_notes' => trim(($locked->admin_notes ? $locked->admin_notes.' | ' : '').$note),
                        'reconciliation_note' => $note,
                        'next_reconciliation_at' => now()->addYears(10),
                    ]);

                    $closed++;
                });
            }
        });

        return $closed;
    }

    // -----------------------------------------------------------------
    // Result checker order
    // -----------------------------------------------------------------

    private function processResultChecker(Carbon $cutoff, bool $execute, int $chunk): array
    {
        $base = fn () => ResultCheckerOrder::query()->where('created_at', '<', $cutoff);
        $closable = fn () => (clone $base())->where('status', 'pending_payment');

        $eligible = (clone $closable())->count();

        $legacyManualReview = (clone $closable())->where('reconciliation_note', 'like', 'manual_review%')->count();
        $legacyPending = $eligible - $legacyManualReview;

        $alreadyTerminal = (clone $base())->where('status', 'failed')->count();

        // 'pending_stock'/'processing' are paid but not yet delivered — the
        // PIN(s) were bought but never handed over. 'completed', or any row
        // with a fulfilled_at timestamp, is positive proof of delivery.
        $paidStates = ['completed', 'pending_stock', 'processing'];
        $paidBase = fn () => (clone $base())->whereIn('status', $paidStates);
        $paidTotal = (clone $paidBase())->count();
        $alreadyFulfilled = (clone $paidBase())->where(function (Builder $q) {
            $q->where('status', 'completed')->orWhereNotNull('fulfilled_at');
        })->count();
        $unsafeAmbiguous = $paidTotal - $alreadyFulfilled;

        $closed = $execute ? $this->closeResultChecker($closable(), $chunk, $cutoff) : 0;

        return [
            'label' => 'Result Checker',
            'eligible' => $eligible,
            'would_close' => $eligible,
            'skipped' => 0,
            'legacy_pending' => $legacyPending,
            'legacy_manual_review' => $legacyManualReview,
            'already_terminal' => $alreadyTerminal,
            'paid' => $paidTotal,
            'already_fulfilled' => $alreadyFulfilled,
            'unsafe_ambiguous' => $unsafeAmbiguous,
            'closed' => $closed,
        ];
    }

    private function closeResultChecker(Builder $query, int $chunk, Carbon $cutoff): int
    {
        $closed = 0;
        $note = $this->closureNote($cutoff);

        $query->select('id')->orderBy('id')->chunkById($chunk, function ($rows) use (&$closed, $note, $cutoff) {
            foreach ($rows as $row) {
                DB::transaction(function () use ($row, $note, $cutoff, &$closed) {
                    $locked = ResultCheckerOrder::whereKey($row->id)->lockForUpdate()->first();

                    if (! $locked
                        || $locked->status !== 'pending_payment'
                        || ($locked->created_at && $locked->created_at->gte($cutoff))
                    ) {
                        return;
                    }

                    ResultCheckerOrder::whereKey($locked->id)->update([
                        'status' => 'failed',
                        'reconciliation_note' => $note,
                        'next_reconciliation_at' => now()->addYears(10),
                    ]);

                    $closed++;
                });
            }
        });

        return $closed;
    }

    // -----------------------------------------------------------------
    // USSD subscription
    // -----------------------------------------------------------------

    private function processUssd(Carbon $cutoff, bool $execute, int $chunk): array
    {
        $base = fn () => UssdSubscription::query()->where('created_at', '<', $cutoff);

        // PaymentHealthService::SCHEMA treats BOTH 'pending_payment' and
        // 'paid' as reconciliation-eligible for USSD. But 'paid' means the
        // gateway payment already succeeded and only activation is
        // outstanding — closing that to a failure status would turn a
        // successful payment into a failed one, which this command must
        // never do. Only 'pending_payment' (genuinely never confirmed) is
        // ever safely closable here; 'paid' is always reported separately
        // as paid-but-unfulfilled and is never touched.
        $eligible = (clone $base())->whereIn('status', [
            UssdSubscription::STATUS_PENDING_PAYMENT,
            UssdSubscription::STATUS_PAID,
        ])->count();

        $closable = fn () => (clone $base())->where('status', UssdSubscription::STATUS_PENDING_PAYMENT);
        $wouldClose = (clone $closable())->count();
        $skipped = $eligible - $wouldClose;

        $legacyManualReview = (clone $closable())->where('reconciliation_note', 'like', 'manual_review%')->count();
        $legacyPending = $wouldClose - $legacyManualReview;

        $alreadyTerminal = (clone $base())->whereIn('status', [
            UssdSubscription::STATUS_PAYMENT_FAILED,
            UssdSubscription::STATUS_CANCELLED,
            UssdSubscription::STATUS_EXPIRED,
            UssdSubscription::STATUS_SUSPENDED,
        ])->count();

        // An ACTIVE subscription is, by definition, fulfilled.
        $paidTotal = (clone $base())->where('status', UssdSubscription::STATUS_ACTIVE)->count();

        $closed = $execute ? $this->closeUssd($closable(), $chunk, $cutoff) : 0;

        return [
            'label' => 'USSD',
            'eligible' => $eligible,
            'would_close' => $wouldClose,
            'skipped' => $skipped,
            'legacy_pending' => $legacyPending,
            'legacy_manual_review' => $legacyManualReview,
            'already_terminal' => $alreadyTerminal,
            'paid' => $paidTotal,
            'already_fulfilled' => $paidTotal,
            // The paid-but-not-activated ('paid') population is exactly the
            // ambiguous case for this payable.
            'unsafe_ambiguous' => $skipped,
            'closed' => $closed,
        ];
    }

    private function closeUssd(Builder $query, int $chunk, Carbon $cutoff): int
    {
        $closed = 0;
        $note = $this->closureNote($cutoff);

        $query->select('id')->orderBy('id')->chunkById($chunk, function ($rows) use (&$closed, $note, $cutoff) {
            foreach ($rows as $row) {
                DB::transaction(function () use ($row, $note, $cutoff, &$closed) {
                    $locked = UssdSubscription::whereKey($row->id)->lockForUpdate()->first();

                    if (! $locked
                        || $locked->status !== UssdSubscription::STATUS_PENDING_PAYMENT
                        || ($locked->created_at && $locked->created_at->gte($cutoff))
                    ) {
                        return;
                    }

                    UssdSubscription::whereKey($locked->id)->update([
                        'status' => UssdSubscription::STATUS_PAYMENT_FAILED,
                        'reconciliation_note' => $note,
                        'next_reconciliation_at' => now()->addYears(10),
                    ]);

                    $closed++;
                });
            }
        });

        return $closed;
    }

    // -----------------------------------------------------------------
    // Wallet top-up
    // -----------------------------------------------------------------

    private function processWalletTopups(Carbon $cutoff, bool $execute, int $chunk): array
    {
        $base = fn () => WalletTopup::query()->where('created_at', '<', $cutoff);
        $closable = fn () => (clone $base())->where('status', 'initiated');

        $eligible = (clone $closable())->count();

        $legacyManualReview = (clone $closable())->where('reconciliation_note', 'like', 'manual_review%')->count();
        $legacyPending = $eligible - $legacyManualReview;

        $alreadyTerminal = (clone $base())->where('status', 'failed')->count();

        // A completed top-up has already credited the wallet — nothing
        // further to fulfil, so the whole paid bucket is already-fulfilled.
        $paidTotal = (clone $base())->where('status', 'completed')->count();

        $closed = $execute ? $this->closeWalletTopups($closable(), $chunk, $cutoff) : 0;

        return [
            'label' => 'Wallet Top-ups',
            'eligible' => $eligible,
            'would_close' => $eligible,
            'skipped' => 0,
            'legacy_pending' => $legacyPending,
            'legacy_manual_review' => $legacyManualReview,
            'already_terminal' => $alreadyTerminal,
            'paid' => $paidTotal,
            'already_fulfilled' => $paidTotal,
            'unsafe_ambiguous' => 0,
            'closed' => $closed,
        ];
    }

    private function closeWalletTopups(Builder $query, int $chunk, Carbon $cutoff): int
    {
        $closed = 0;
        $note = $this->closureNote($cutoff);

        $query->select('id')->orderBy('id')->chunkById($chunk, function ($rows) use (&$closed, $note, $cutoff) {
            foreach ($rows as $row) {
                DB::transaction(function () use ($row, $note, $cutoff, &$closed) {
                    $locked = WalletTopup::whereKey($row->id)->lockForUpdate()->first();

                    if (! $locked
                        || $locked->status !== 'initiated'
                        || ($locked->created_at && $locked->created_at->gte($cutoff))
                    ) {
                        return;
                    }

                    WalletTopup::whereKey($locked->id)->update([
                        'status' => 'failed',
                        'reconciliation_note' => $note,
                        'next_reconciliation_at' => now()->addYears(10),
                    ]);

                    $closed++;
                });
            }
        });

        return $closed;
    }

    // -----------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------

    /**
     * The one audit/note mechanism every payable already has
     * (reconciliation_note, Phase 3) — no new column or status is invented.
     * reconciliation_attempts/last_reconciliation_at are deliberately left
     * untouched by every close*() method above: they record the history of
     * actual gateway-verification attempts, and this command never performs
     * one, so that history is preserved exactly as it was.
     */
    private function closureNote(Carbon $cutoff): string
    {
        $note = sprintf(
            'Legacy historical record closed administratively on %s (payments:close-legacy --before=%s). External service was previously delivered. Automatic reconciliation/fulfillment disabled.',
            now()->toDateString(),
            $cutoff->toDateString()
        );

        // reconciliation_note is a string(255) column on every payable table.
        return mb_substr($note, 0, 255);
    }

    private function renderMainTable(array $results): void
    {
        $rows = [];
        foreach ($results as $r) {
            $rows[] = [$r['label'], $r['eligible'], $r['would_close'], $r['skipped']];
        }

        $this->table(['Type', 'Eligible', 'Would close', 'Skipped'], $rows);
    }

    private function renderClassificationTable(array $results): void
    {
        $rows = [];
        foreach ($results as $r) {
            $rows[] = [
                $r['label'],
                $r['legacy_pending'],
                $r['legacy_manual_review'],
                $r['already_terminal'],
                $r['paid'],
                $r['already_fulfilled'],
                $r['unsafe_ambiguous'],
            ];
        }

        $this->newLine();
        $this->info('Classification breakdown (Paid/Already fulfilled/Unsafe-ambiguous records are informational only — never modified by this command):');
        $this->table(
            ['Type', 'Legacy pending', 'Legacy manual review', 'Already terminal', 'Paid', 'Already fulfilled', 'Unsafe/ambiguous'],
            $rows
        );
    }
}
