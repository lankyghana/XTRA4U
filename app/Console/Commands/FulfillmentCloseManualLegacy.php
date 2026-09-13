<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Closes historical orders that were paid successfully, could not be sent
 * through external fulfillment while the provider was offline, were
 * subsequently delivered by the vendor manually, and were marked Completed
 * through the normal vendor/admin workflow — but whose
 * `external_fulfillment_status` never recorded that success, so they still
 * read as "paid orders awaiting external fulfillment"
 * (PaymentHealthService::queueHealth()) and remain exposed to
 * ProcessExternalFulfillment, which gates purely on `payment_status` +
 * `external_fulfillment_status` and does NOT check `status` at all — a
 * stray re-dispatch of that job by order id would still try to submit one
 * of these orders to the external API a second time even though the order
 * is already `status = 'Completed'`.
 *
 * This is a pure DATABASE STATE CLEANUP. It never talks to a payment
 * gateway or an external fulfillment provider, never dispatches
 * ProcessExternalFulfillment or SyncExternalFulfillmentStatuses, never
 * creates a Transaction, never touches a wallet, and never deletes a
 * record. Its only job is to stamp `external_fulfillment_status =
 * 'succeeded'` — the one value ProcessExternalFulfillment's own re-entry
 * guard (`if ($order->external_fulfillment_status === 'succeeded') return;`)
 * and ExternalFulfillmentStatusSynchronizer already understand as "nothing
 * left to do here" — on orders where every other signal already proves the
 * order is done. No new status value or column is invented anywhere in
 * this command.
 *
 * Eligibility (all must hold, re-verified under a row lock immediately
 * before every write):
 *   1. `payment_status` IN ('paid','completed')                — payment succeeded.
 *   2. `status` = 'Completed'                                   — the vendor/admin
 *      workflow (VendorFulfillmentController::complete() / AdminOrderController)
 *      already says this order is done.
 *   3. `external_fulfillment_status` IS NULL or 'failed'        — never
 *      recorded as successful, and not currently in an ambiguous in-flight
 *      state (see "Ambiguous/unsafe" below).
 *   4. `created_at` < --before.
 *
 * Explicitly excluded and reported separately, never modified:
 *   - Any order not in a paid state (unpaid/pending/failed payments).
 *   - Paid orders whose `status` is not 'Completed' ("paid but vendor-not-
 *     completed" — the exact population PaymentHealthService already warns
 *     about; this command must never guess that one of those was secretly
 *     delivered).
 *   - Paid + Completed orders whose `external_fulfillment_status` is
 *     already 'succeeded' ("already externally fulfilled" — nothing to do).
 *   - Paid + Completed orders whose `external_fulfillment_status` is
 *     'processing' (or any value outside {null, failed, succeeded}) —
 *     "ambiguous/unsafe": a Completed order should never still be
 *     "processing" at the provider, and that contradiction is exactly the
 *     kind of record this command must leave for a human rather than
 *     silently resolve.
 */
class FulfillmentCloseManualLegacy extends Command
{
    protected $signature = 'fulfillment:close-manual-legacy
                            {--before= : Cutoff date (YYYY-MM-DD). Only orders created strictly before this date are ever considered. Required.}
                            {--execute : Actually write the closure. Without this flag the command only reports what it would do (dry run — the default).}
                            {--chunk=200 : Number of matching orders processed per batch/transaction.}';

    protected $description = 'Administratively close historical paid+Completed orders that were manually delivered while external fulfillment never recorded success — stops them from appearing as awaiting-fulfillment and from ever being resubmitted to the external API. Never contacts a gateway or provider.';

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

        $execute = (bool) $this->option('execute');
        $chunk = max(1, (int) $this->option('chunk'));

        $this->info($execute
            ? "EXECUTING closure for manually-delivered legacy orders created before {$cutoff->toDateString()}..."
            : "DRY RUN (default) — zero database writes will be made. Cutoff: created before {$cutoff->toDateString()}.");
        $this->newLine();

        $result = $this->processOrders($cutoff, $execute, $chunk);

        $this->renderReport($result);

        if ($execute) {
            $this->newLine();
            $this->info("Orders: closed {$result['closed']} record(s).");
        } else {
            $this->newLine();
            $this->warn('This was a DRY RUN. No records were modified. Re-run with --execute to apply.');
        }

        Log::info('fulfillment:close-manual-legacy run completed', [
            'before' => $cutoff->toDateString(),
            'execute' => $execute,
            'result' => $result,
        ]);

        return self::SUCCESS;
    }

    private function processOrders(Carbon $cutoff, bool $execute, int $chunk): array
    {
        $paidStates = ['paid', 'completed'];

        $paidBase = fn () => Order::query()
            ->where('created_at', '<', $cutoff)
            ->whereIn('payment_status', $paidStates);

        $paidTotal = (clone $paidBase())->count();

        $completedBase = fn () => (clone $paidBase())->where('status', 'Completed');
        $completedTotal = (clone $completedBase())->count();

        $paidNotCompleted = $paidTotal - $completedTotal;

        $alreadyFulfilled = (clone $completedBase())->where('external_fulfillment_status', 'succeeded')->count();

        $closable = fn () => (clone $completedBase())->where(function (Builder $q) {
            $q->whereNull('external_fulfillment_status')->orWhere('external_fulfillment_status', 'failed');
        });
        $eligible = (clone $closable())->count();

        $ambiguousUnsafe = $completedTotal - $alreadyFulfilled - $eligible;

        $closed = $execute ? $this->closeOrders($closable(), $chunk, $cutoff) : 0;

        return [
            'paid_total' => $paidTotal,
            'paid_not_completed' => $paidNotCompleted,
            'already_fulfilled' => $alreadyFulfilled,
            'eligible' => $eligible,
            'ambiguous_unsafe' => $ambiguousUnsafe,
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

                    // Re-verify every eligibility criterion under the lock.
                    // A record that has since changed (a real fulfillment
                    // success/failure landed, the vendor un-completed it,
                    // etc.) is left exactly as-is.
                    if (! $locked
                        || ! in_array($locked->payment_status, ['paid', 'completed'], true)
                        || $locked->status !== 'Completed'
                        || ! (is_null($locked->external_fulfillment_status) || $locked->external_fulfillment_status === 'failed')
                        || ($locked->created_at && $locked->created_at->gte($cutoff))
                    ) {
                        return;
                    }

                    // Only external_fulfillment_status/completed_at/delivered_at
                    // and the audit note are written. Every other field —
                    // payment_status, payment_reference, amount_paid,
                    // vendor/customer identifiers, external_fulfillment_
                    // provider_used/attempts/last_attempt_at/remote_reference/
                    // last_error (the historical provider record) — is left
                    // completely untouched.
                    Order::whereKey($locked->id)->update([
                        'external_fulfillment_status' => 'succeeded',
                        'external_fulfillment_completed_at' => $locked->external_fulfillment_completed_at ?: now(),
                        'external_fulfillment_delivered_at' => $locked->external_fulfillment_delivered_at ?: now(),
                        'reconciliation_note' => $note,
                    ]);

                    $closed++;
                });
            }
        });

        return $closed;
    }

    /**
     * reconciliation_note is the one audit/note mechanism every order
     * already has (Phase 3) — no new column is invented. All other
     * fulfillment fields (provider used, attempts, last error, remote
     * reference) are left untouched so the historical provider record —
     * including whatever it recorded about the outage — survives intact.
     */
    private function closureNote(Carbon $cutoff): string
    {
        $note = sprintf(
            'Fulfillment closed administratively on %s (fulfillment:close-manual-legacy --before=%s). Order was already marked Completed by the vendor; external fulfillment never recorded success (provider outage). Not resubmitted to the external API.',
            now()->toDateString(),
            $cutoff->toDateString()
        );

        // reconciliation_note is a string(255) column.
        return mb_substr($note, 0, 255);
    }

    private function renderReport(array $r): void
    {
        $this->table(
            ['Metric', 'Count'],
            [
                ['Paid orders (before cutoff)', $r['paid_total']],
                ['  Paid but vendor-not-completed', $r['paid_not_completed']],
                ['  Paid + Completed: already externally fulfilled', $r['already_fulfilled']],
                ['  Paid + Completed: eligible manually-completed (would close)', $r['eligible']],
                ['  Paid + Completed: ambiguous/unsafe (skipped)', $r['ambiguous_unsafe']],
            ]
        );
    }
}
