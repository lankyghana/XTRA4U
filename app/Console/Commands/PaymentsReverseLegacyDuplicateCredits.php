<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Models\WalletLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reverses the duplicate vendor wallet credits created on a single incident
 * date when a batch of already-completed historical orders had
 * `PaymentService::completeOrder()` run against them a second time.
 *
 * The incident (2026-09-13): 169 orders created before that date — already
 * manually completed and already credited to their vendors long ago — had
 * `completeOrder()`/`completeResellerOrder()` execute again. Every such run
 * unconditionally calls `$vendor->increment('wallet_balance', ...)` (see
 * PaymentService::completeRegularOrder()/completeResellerOrder()), even when
 * `upsertOrderTransaction()` itself declines to rewrite an already-final
 * Transaction row — so every one of those 169 orders' vendor(s) were
 * credited a second time today, on top of whatever they were legitimately
 * credited historically.
 *
 * Eligibility (all must hold, re-verified under a row lock immediately
 * before every write):
 *   1. `orders.created_at` < the incident date (start of day)   — the order predates the incident.
 *   2. `orders.payment_completed_at` falls on the incident date — the smoking gun: this
 *      historical order's completion timestamp was stamped TODAY, not when it actually
 *      completed.
 *   3. `orders.payment_status` IN ('paid','completed')          — completion is
 *      actually persisted, not merely attempted.
 *   4. `orders.duplicate_credit_reversed_at` IS NULL             — not already reversed.
 *
 * The exact duplicate amount is never guessed, never derived from a current
 * wallet-balance diff, and never recomputed from product pricing. It is read
 * straight off the `vendor_earning` column of each affected order's own
 * `transactions` rows (payment_type='order', payment_status IN
 * ('successful','completed')) grouped by `vendor_id` — the exact same
 * financial value PaymentService's own wallet increment used today, and the
 * same source `WalletService::reverseOrderEarnings()` already uses for its
 * own (differently-scoped) order-refund reversal. For a reseller order this
 * naturally covers BOTH the owner-vendor row and the reseller-vendor row (and
 * every leg of a deeper multi-level chain, if present) — there is nothing
 * reseller-specific to special-case, because every vendor who earned
 * something on the order has their own Transaction row.
 *
 * This is a pure DATABASE STATE CLEANUP. It never talks to a payment gateway
 * or an external fulfillment provider, never dispatches a fulfillment job,
 * never sends SMS/email, never deletes a Transaction row, and never touches
 * `payment_status`, `status`, `external_fulfillment_status`, or
 * `payment_reference`. Its only writes are: decrementing the exact duplicated
 * amount off `vendors.wallet_balance`, a `WalletLedger` debit audit entry per
 * (order, vendor) reversed, and stamping `orders.duplicate_credit_reversed_at`
 * so neither this command nor anything else can ever reverse the same order
 * twice.
 *
 * Vendor-level all-or-nothing: if a vendor's CURRENT wallet balance cannot
 * cover the full amount still owed back across every one of their affected
 * orders, NONE of that vendor's orders are touched by --execute (not even
 * partially) — every one of them is left for manual review. This mirrors
 * `WalletService::reverseOrderEarnings()`'s own all-or-nothing philosophy;
 * this command only extends it across the whole incident per vendor, because
 * the dry-run report itself is scoped per vendor.
 */
class PaymentsReverseLegacyDuplicateCredits extends Command
{
    protected $signature = 'payments:reverse-legacy-duplicate-credits
                            {--date= : Incident date (YYYY-MM-DD) — the day historical orders were mistakenly re-completed. Required.}
                            {--execute : Actually write the reversal. Without this flag the command only reports what it would do (dry run — the default).}
                            {--chunk=200 : Number of affected orders processed per batch/transaction.}';

    protected $description = 'Reverse duplicate vendor wallet credits created when already-completed historical orders were re-completed on a single incident date. Never contacts a gateway/provider; defaults to dry-run.';

    /** Audit trail source tag written into every WalletLedger entry this command creates. */
    public const LEDGER_SOURCE = 'legacy_duplicate_credit_reversal';

    public function handle(): int
    {
        $dateOption = $this->option('date');
        if (! is_string($dateOption) || trim($dateOption) === '') {
            $this->error('--date=YYYY-MM-DD is required.');

            return self::FAILURE;
        }

        try {
            $incidentStart = Carbon::parse($dateOption)->startOfDay();
        } catch (\Throwable $e) {
            $this->error("Invalid --date '{$dateOption}': {$e->getMessage()}");

            return self::FAILURE;
        }

        $incidentEnd = (clone $incidentStart)->addDay();
        $execute = (bool) $this->option('execute');
        $chunk = max(1, (int) $this->option('chunk'));

        $this->info($execute
            ? "EXECUTING reversal of duplicate wallet credits for orders completed on {$incidentStart->toDateString()}..."
            : "DRY RUN (default) — zero database writes will be made. Incident date: {$incidentStart->toDateString()}.");
        $this->newLine();

        $analysis = $this->analyze($incidentStart, $incidentEnd);

        if ($analysis['order_ids']->isEmpty()) {
            $this->info('No orders match the incident criteria. Nothing to do.');

            return self::SUCCESS;
        }

        $vendorReport = $this->buildVendorReport($analysis);

        $result = $execute
            ? $this->executeReversal($analysis, $vendorReport, $chunk)
            : ['reversed_orders' => 0, 'reversed_vendors' => 0, 'reversed_total' => 0.0];

        $this->renderVendorTable($vendorReport);
        $this->renderTotals($analysis, $vendorReport, $result, $execute);

        Log::info('payments:reverse-legacy-duplicate-credits run completed', [
            'incident_date' => $incidentStart->toDateString(),
            'execute' => $execute,
            'affected_orders' => $analysis['order_ids']->count(),
            'affected_vendors' => count($vendorReport),
            'result' => $result,
        ]);

        return self::SUCCESS;
    }

    /**
     * Gather the incident's affected orders and the exact per-(order,vendor)
     * duplicate amount, split into "already reversed" and "still pending"
     * buckets. Read-only — used for both the dry-run report and to decide,
     * before ever writing, which vendors are safe to touch.
     *
     * @return array{
     *     order_ids: \Illuminate\Support\Collection<int,int>,
     *     pending_by_order_vendor: array<int,array<int,float>>,
     *     pending_by_vendor: array<int,float>,
     *     already_reversed_by_vendor: array<int,float>,
     * }
     */
    private function analyze(Carbon $incidentStart, Carbon $incidentEnd): array
    {
        $eligibleQuery = fn () => Order::query()
            ->where('created_at', '<', $incidentStart)
            ->where('payment_completed_at', '>=', $incidentStart)
            ->where('payment_completed_at', '<', $incidentEnd)
            ->whereIn('payment_status', ['paid', 'completed']);

        $orderIds = (clone $eligibleQuery())->pluck('id');

        if ($orderIds->isEmpty()) {
            return [
                'order_ids' => $orderIds,
                'pending_by_order_vendor' => [],
                'pending_by_vendor' => [],
                'already_reversed_by_vendor' => [],
            ];
        }

        $alreadyReversedOrderIds = (clone $eligibleQuery())
            ->whereNotNull('duplicate_credit_reversed_at')
            ->pluck('id')
            ->flip();

        // The exact, already-persisted per-vendor earning for each affected
        // order — never a recomputation, never a current-balance diff.
        $earningRows = Transaction::query()
            ->whereIn('order_id', $orderIds)
            ->where('payment_type', 'order')
            ->whereIn('payment_status', ['successful', 'completed'])
            ->where('vendor_earning', '>', 0)
            ->get(['order_id', 'vendor_id', 'vendor_earning']);

        $pendingByOrderVendor = [];
        $pendingByVendor = [];
        $alreadyReversedByVendor = [];

        foreach ($earningRows as $row) {
            $orderId = (int) $row->order_id;
            $vendorId = (int) $row->vendor_id;
            $amount = round((float) $row->vendor_earning, 2);

            if ($amount <= 0.0) {
                continue;
            }

            if (isset($alreadyReversedOrderIds[$orderId])) {
                $alreadyReversedByVendor[$vendorId] = round(($alreadyReversedByVendor[$vendorId] ?? 0.0) + $amount, 2);

                continue;
            }

            $pendingByOrderVendor[$orderId][$vendorId] = round(($pendingByOrderVendor[$orderId][$vendorId] ?? 0.0) + $amount, 2);
            $pendingByVendor[$vendorId] = round(($pendingByVendor[$vendorId] ?? 0.0) + $amount, 2);
        }

        return [
            'order_ids' => $orderIds,
            'pending_by_order_vendor' => $pendingByOrderVendor,
            'pending_by_vendor' => $pendingByVendor,
            'already_reversed_by_vendor' => $alreadyReversedByVendor,
        ];
    }

    /**
     * @return array<int,array{vendor_id:int,vendor_name:?string,current_balance:?float,duplicate_credit:float,pending:float,result:string}>
     */
    private function buildVendorReport(array $analysis): array
    {
        $vendorIds = collect(array_keys($analysis['pending_by_vendor']))
            ->merge(array_keys($analysis['already_reversed_by_vendor']))
            ->unique()
            ->sort()
            ->values();

        $vendors = Vendor::whereIn('id', $vendorIds)->get(['id', 'name', 'wallet_balance'])->keyBy('id');

        $report = [];

        foreach ($vendorIds as $vendorId) {
            $vendor = $vendors->get($vendorId);
            $pending = $analysis['pending_by_vendor'][$vendorId] ?? 0.0;
            $alreadyReversed = $analysis['already_reversed_by_vendor'][$vendorId] ?? 0.0;
            $duplicateCredit = round($pending + $alreadyReversed, 2);
            $currentBalance = $vendor ? (float) $vendor->wallet_balance : null;

            if (! $vendor) {
                $result = 'SKIPPED';
            } elseif ($pending <= 0.0) {
                $result = 'ALREADY REVERSED';
            } elseif ($currentBalance >= $pending) {
                $result = 'SAFE TO REVERSE';
            } else {
                $result = 'INSUFFICIENT BALANCE';
            }

            $report[$vendorId] = [
                'vendor_id' => $vendorId,
                'vendor_name' => $vendor?->name,
                'current_balance' => $currentBalance,
                'duplicate_credit' => $duplicateCredit,
                'pending' => $pending,
                'result' => $result,
            ];
        }

        return $report;
    }

    /**
     * @param  array<int,array{result:string}>  $vendorReport
     */
    private function executeReversal(array $analysis, array $vendorReport, int $chunk): array
    {
        $safeVendorIds = collect($vendorReport)
            ->filter(fn ($row) => $row['result'] === 'SAFE TO REVERSE')
            ->keys()
            ->flip();

        $reversedOrders = 0;
        $touchedVendors = [];
        $reversedTotal = 0.0;

        $orderIds = array_keys($analysis['pending_by_order_vendor']);
        sort($orderIds);

        foreach (array_chunk($orderIds, $chunk) as $batch) {
            foreach ($batch as $orderId) {
                $vendorAmounts = $analysis['pending_by_order_vendor'][$orderId];

                // Every vendor on this order must be individually safe. A
                // reseller order with one safe leg and one insufficient leg
                // is left completely untouched — never partially reversed.
                $allVendorsSafe = true;
                foreach (array_keys($vendorAmounts) as $vendorId) {
                    if (! isset($safeVendorIds[$vendorId])) {
                        $allVendorsSafe = false;
                        break;
                    }
                }

                if (! $allVendorsSafe) {
                    continue;
                }

                $outcome = $this->reverseOrder($orderId, $vendorAmounts);

                if ($outcome !== null) {
                    $reversedOrders++;
                    foreach ($outcome as $vendorId => $amount) {
                        $touchedVendors[$vendorId] = true;
                        $reversedTotal = round($reversedTotal + $amount, 2);
                    }
                }
            }
        }

        return [
            'reversed_orders' => $reversedOrders,
            'reversed_vendors' => count($touchedVendors),
            'reversed_total' => $reversedTotal,
        ];
    }

    /**
     * Reverse a single order's duplicate credit inside its own DB
     * transaction. Returns the map of vendor_id => amount actually
     * decremented, or null if the order was skipped (already reversed,
     * no longer matches the incident criteria, or a vendor's balance is
     * insufficient at write time).
     *
     * @param  array<int,float>  $expectedVendorAmounts  Precomputed at analysis time — re-derived
     *                                                   fresh under the lock below rather than trusted directly.
     * @return array<int,float>|null
     */
    private function reverseOrder(int $orderId, array $expectedVendorAmounts): ?array
    {
        return DB::transaction(function () use ($orderId, $expectedVendorAmounts) {
            $order = Order::whereKey($orderId)->lockForUpdate()->first();

            if (! $order
                || $order->duplicate_credit_reversed_at !== null
                || ! in_array($order->payment_status, ['paid', 'completed'], true)
                || $order->payment_completed_at === null
            ) {
                return null;
            }

            // Re-derive the exact duplicate amount fresh, under the lock —
            // never trust the precomputed figure for the write itself.
            $currentEarnings = Transaction::where('order_id', $order->id)
                ->where('payment_type', 'order')
                ->whereIn('payment_status', ['successful', 'completed'])
                ->where('vendor_earning', '>', 0)
                ->get(['vendor_id', 'vendor_earning'])
                ->groupBy('vendor_id')
                ->map(fn ($rows) => round((float) $rows->sum('vendor_earning'), 2));

            if ($currentEarnings->isEmpty()) {
                // Nothing to reverse, but still mark it so it is never
                // re-evaluated as "pending" again.
                Order::whereKey($order->id)->update(['duplicate_credit_reversed_at' => now()]);

                return null;
            }

            // A row that disappeared or changed since analysis (e.g. a
            // concurrent legitimate correction) means this order no longer
            // matches what was analyzed — skip it for a human to re-run.
            if ($currentEarnings->keys()->sort()->values()->all() !== collect(array_keys($expectedVendorAmounts))->sort()->values()->all()) {
                return null;
            }
            foreach ($currentEarnings as $vendorId => $amount) {
                if (abs($amount - ($expectedVendorAmounts[(int) $vendorId] ?? -1)) > 0.001) {
                    return null;
                }
            }

            $vendorIds = $currentEarnings->keys()->map(fn ($id) => (int) $id)->sort()->values();
            $vendors = Vendor::whereIn('id', $vendorIds)->lockForUpdate()->get()->keyBy('id');

            // All-or-nothing at write time too: re-confirm every vendor still
            // has enough balance right now, not just at analysis time.
            foreach ($vendorIds as $vendorId) {
                $vendor = $vendors->get($vendorId);
                $amount = $currentEarnings[$vendorId];

                if (! $vendor || (float) $vendor->wallet_balance < $amount) {
                    return null;
                }
            }

            $deductions = [];

            foreach ($vendorIds as $vendorId) {
                $vendor = $vendors->get($vendorId);
                $amount = $currentEarnings[$vendorId];

                $vendor->decrement('wallet_balance', $amount);
                $vendor->refresh();

                WalletLedger::create([
                    'vendor_id' => $vendorId,
                    'type' => 'debit',
                    'source' => self::LEDGER_SOURCE,
                    'amount' => $amount,
                    'balance_after' => (float) $vendor->wallet_balance,
                    'metadata' => [
                        'order_id' => $order->id,
                        'incident_date' => $order->payment_completed_at->toDateString(),
                        'reason' => 'Duplicate wallet credit from historical order re-completion reversed administratively.',
                        'command' => 'payments:reverse-legacy-duplicate-credits',
                    ],
                ]);

                $deductions[$vendorId] = $amount;
            }

            Order::whereKey($order->id)->update(['duplicate_credit_reversed_at' => now()]);

            return $deductions;
        });
    }

    /**
     * @param  array<int,array{vendor_id:int,vendor_name:?string,current_balance:?float,duplicate_credit:float,pending:float,result:string}>  $vendorReport
     */
    private function renderVendorTable(array $vendorReport): void
    {
        $rows = collect($vendorReport)->map(fn ($row) => [
            $row['vendor_name'] ?? "Vendor #{$row['vendor_id']}",
            $row['current_balance'] === null ? 'N/A' : 'GHS '.number_format($row['current_balance'], 2),
            'GHS '.number_format($row['duplicate_credit'], 2),
            $row['result'],
        ])->all();

        $this->table(['Vendor', 'Current Wallet', 'Duplicate Credit', 'Result'], $rows);
    }

    private function renderTotals(array $analysis, array $vendorReport, array $result, bool $execute): void
    {
        $duplicateTotal = round(collect($vendorReport)->sum('duplicate_credit'), 2);
        $safeTotal = round(collect($vendorReport)->where('result', 'SAFE TO REVERSE')->sum('pending'), 2);
        $manualReviewTotal = round(collect($vendorReport)->where('result', 'INSUFFICIENT BALANCE')->sum('pending'), 2);

        $this->newLine();
        $this->table(
            ['Metric', 'Value'],
            [
                ['Affected orders', $analysis['order_ids']->count()],
                ['Affected vendors', count($vendorReport)],
                ['Duplicate credit total', 'GHS '.number_format($duplicateTotal, 2)],
                ['Safe-to-reverse total', 'GHS '.number_format($safeTotal, 2)],
                ['Manual-review total', 'GHS '.number_format($manualReviewTotal, 2)],
            ]
        );

        $this->newLine();
        if ($execute) {
            $this->info("Orders reversed: {$result['reversed_orders']}. Vendors touched: {$result['reversed_vendors']}. Total decremented: GHS ".number_format($result['reversed_total'], 2).'.');
        } else {
            $this->warn('This was a DRY RUN. No records were modified. Re-run with --execute to apply.');
        }
    }
}
