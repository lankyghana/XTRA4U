<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Closes historical orders that were paid successfully and, per
 * administrator investigation, already delivered — but whose
 * `external_fulfillment_status` never recorded that, so they still match
 * PaymentHealthService::queueHealth()'s exact "awaiting external
 * fulfillment" query:
 *
 *     payment_status IN ('paid','completed')
 *     AND (external_fulfillment_status IS NULL
 *          OR external_fulfillment_status IN ('pending','queued','processing'))
 *
 * Unlike `fulfillment:close-manual-legacy` (which only ever touches orders
 * the vendor/admin workflow already marked `status = 'Completed'`), this
 * command deliberately does NOT gate on `status` at all — it mirrors
 * Payment Health's own criteria exactly, because the administrator has
 * separately confirmed that every historical order up to the cutoff was
 * already delivered regardless of what `status` happens to read today
 * (Processing/Completed/Cancelled/Pending all appear in the confirmed
 * population). `status` is therefore never read as an eligibility
 * condition and never written — whatever value it holds going in is
 * exactly what it holds coming out.
 *
 * This is a pure DATABASE STATE CLEANUP. It never talks to a payment
 * gateway or an external fulfillment provider, never dispatches
 * ProcessExternalFulfillment or SyncExternalFulfillmentStatuses, never
 * creates a Transaction, never touches a wallet, and never deletes a
 * record. Its only job is to stamp `external_fulfillment_status =
 * 'succeeded'` — the same terminal value `fulfillment:close-manual-legacy`
 * already uses, and the one value:
 *   - ProcessExternalFulfillment's re-entry guard
 *     (`if ($order->external_fulfillment_status === 'succeeded') return;`),
 *   - ExternalFulfillmentStatusSynchronizer::apply() (now also short-
 *     circuited by the `reconciliation_note` marker this command writes —
 *     see below), and
 *   - SyncExternalFulfillmentStatuses' polling query (excluded via that
 *     same marker)
 * already understand as "nothing left to do here". No new status value or
 * column is invented anywhere in this command.
 *
 * Why the `reconciliation_note` marker matters here specifically: a
 * Processing order stamped `external_fulfillment_status = 'succeeded'`
 * with a `external_fulfillment_provider_used`/`external_fulfillment_
 * remote_reference` already on file (the "Processing + processing"
 * subset of this command's population) is otherwise exactly the shape
 * SyncExternalFulfillmentStatuses' polling job is designed to actively
 * re-check with the provider and auto-complete once confirmed — which
 * would both contact the external API and silently flip `status` to
 * 'Completed' for an order this command must leave untouched. Writing the
 * same "Fulfillment closed administratively" note prefix this command and
 * `fulfillment:close-manual-legacy` both use lets
 * ExternalFulfillmentStatusSynchronizer and the polling job recognise and
 * permanently skip these records, without this command having to touch
 * `external_fulfillment_provider_used` or `external_fulfillment_remote_
 * reference` (the historical provider record, left completely intact).
 *
 * Eligibility (all must hold, re-verified under a row lock immediately
 * before every write):
 *   1. `payment_status` IN ('paid','completed')                    — payment succeeded.
 *   2. `external_fulfillment_status` IS NULL, or IN ('pending',
 *      'queued','processing')                                      — the exact
 *      Payment Health "awaiting fulfillment" definition.
 *   3. `created_at` < --before.
 *
 * Explicitly excluded and reported separately, never modified:
 *   - Any order not in a paid state (unpaid/pending/failed payments).
 *   - Paid orders whose `external_fulfillment_status` is already
 *     'succeeded' ("already externally fulfilled" — nothing to do).
 *   - Paid orders whose `external_fulfillment_status` is 'failed' — a
 *     distinct, already-visible population (PaymentHealthService's
 *     `orders_fulfillment_failed`) this command leaves for a human.
 */
class FulfillmentClosePaidLegacy extends Command
{
    protected $signature = 'fulfillment:close-paid-legacy
                            {--before= : Cutoff date (YYYY-MM-DD). Only orders created strictly before this date are ever considered. Required.}
                            {--execute : Actually write the closure. Without this flag the command only reports what it would do (dry run — the default).}
                            {--chunk=200 : Number of matching orders processed per batch/transaction.}';

    protected $description = 'Administratively close historical paid orders that Payment Health reports as awaiting external fulfillment (NULL/pending/queued/processing) but which were already delivered — without touching payment data, order status, or ever contacting a gateway/provider.';

    /**
     * The exact non-null values PaymentHealthService::queueHealth() treats
     * as "awaiting fulfillment" alongside NULL. 'failed' is deliberately
     * excluded — it is a distinct, already-visible population.
     */
    private const AWAITING_STATUSES = ['pending', 'queued', 'processing'];

    /**
     * Shared marker prefix with `fulfillment:close-manual-legacy`.
     * ExternalFulfillmentStatusSynchronizer and SyncExternalFulfillmentStatuses
     * both recognise it to permanently skip administratively-closed orders.
     */
    public const CLOSURE_NOTE_PREFIX = 'Fulfillment closed administratively';

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
            ? "EXECUTING closure for paid legacy orders awaiting fulfillment, created before {$cutoff->toDateString()}..."
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

        Log::info('fulfillment:close-paid-legacy run completed', [
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

        $awaiting = fn () => (clone $paidBase())->where(function (Builder $q) {
            $q->whereNull('external_fulfillment_status')
                ->orWhereIn('external_fulfillment_status', self::AWAITING_STATUSES);
        });
        $eligible = (clone $awaiting())->count();

        $alreadyFulfilled = (clone $paidBase())->where('external_fulfillment_status', 'succeeded')->count();
        $fulfillmentFailed = (clone $paidBase())->where('external_fulfillment_status', 'failed')->count();

        $byStatus = (clone $awaiting())
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->orderBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $closed = $execute ? $this->closeOrders($awaiting(), $chunk, $cutoff) : 0;

        return [
            'paid_total' => $paidTotal,
            'already_fulfilled' => $alreadyFulfilled,
            'fulfillment_failed' => $fulfillmentFailed,
            'eligible' => $eligible,
            'eligible_by_status' => $byStatus,
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
                    // success/failure landed, the payment was reversed,
                    // etc.) is left exactly as-is. `status` is deliberately
                    // never checked here — it is neither read nor written.
                    if (! $locked
                        || ! in_array($locked->payment_status, ['paid', 'completed'], true)
                        || ! (is_null($locked->external_fulfillment_status) || in_array($locked->external_fulfillment_status, self::AWAITING_STATUSES, true))
                        || ($locked->created_at && $locked->created_at->gte($cutoff))
                    ) {
                        return;
                    }

                    // Only external_fulfillment_status/completed_at/
                    // delivered_at and the audit note are written. Every
                    // other field — status, payment_status, payment_
                    // reference, amount_paid, vendor/customer identifiers,
                    // external_fulfillment_provider_used/attempts/
                    // last_attempt_at/remote_reference/last_error (the
                    // historical provider record) — is left completely
                    // untouched.
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
     * already has (Phase 3) — no new column is invented. It doubles as the
     * marker ExternalFulfillmentStatusSynchronizer and
     * SyncExternalFulfillmentStatuses use to permanently skip these
     * records (see the class docblock). All other fulfillment fields
     * (provider used, attempts, last error, remote reference) are left
     * untouched so the historical provider record survives intact.
     */
    private function closureNote(Carbon $cutoff): string
    {
        $note = sprintf(
            '%s on %s (fulfillment:close-paid-legacy --before=%s). Payment succeeded; administrator confirmed this order was already delivered. External fulfillment status corrected to prevent resubmission. Order status and payment records unchanged.',
            self::CLOSURE_NOTE_PREFIX,
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
                ['  Already externally fulfilled (succeeded)', $r['already_fulfilled']],
                ['  External fulfillment failed (left for human)', $r['fulfillment_failed']],
                ['  Awaiting fulfillment: eligible (would close)', $r['eligible']],
            ]
        );

        if ($r['eligible_by_status'] !== []) {
            $this->newLine();
            $this->line('Eligible breakdown by order status:');
            $this->table(
                ['Order status', 'Count'],
                collect($r['eligible_by_status'])->map(fn ($count, $status) => [$status ?: '(none)', $count])->values()->all()
            );
        }
    }
}
