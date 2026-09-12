<?php

namespace App\Services;

use App\Models\AdminNotification;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Models\VendorNotification;
use App\Models\WalletLedger;
use App\Models\WalletTopup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WalletService
{
    /**
     * The single authoritative WalletTopup completion pipeline. Every caller
     * that can conclude a top-up succeeded — the browser callback, the
     * status-polling endpoint, and the automatic PaymentReconciliationService
     * — must go through here rather than crediting inline, so there is
     * exactly one place that credits a wallet for a top-up.
     *
     * Idempotent and race-safe: locks the WalletTopup row and re-checks its
     * status before crediting, so two callers racing the same reference
     * (double-click, webhook + browser callback + reconciler) can only ever
     * credit once. $verification is stored verbatim as an audit trail —
     * the credited amount always comes from $topup->amount (what the vendor
     * actually requested), never from the gateway's reported amount, and a
     * reported amount below that expected amount refuses to credit at all
     * (the same financial-integrity guard every other completion path uses).
     *
     * Returns true if the top-up is 'completed' when this call returns —
     * whether this call did the crediting or a race already had.
     */
    public function completeTopup(WalletTopup $topup, array $verification): bool
    {
        $creditedNow = false;
        $alreadyCompleted = false;

        DB::transaction(function () use ($topup, $verification, &$creditedNow, &$alreadyCompleted) {
            $locked = WalletTopup::whereKey($topup->id)->lockForUpdate()->first();

            if (! $locked || $locked->status === 'completed') {
                $alreadyCompleted = (bool) $locked && $locked->status === 'completed';

                return;
            }

            $vendorId = (int) $locked->vendor_id;
            $expectedAmount = (float) $locked->amount;

            if ($vendorId <= 0 || $expectedAmount <= 0) {
                Log::warning('WalletService::completeTopup: invalid topup record, refusing to credit', [
                    'topup_id' => $locked->id,
                ]);

                return;
            }

            $reportedAmount = data_get($verification, 'data.amount');
            if ($reportedAmount !== null && round((float) $reportedAmount, 2) < round($expectedAmount, 2)) {
                Log::error('WalletService::completeTopup: verified amount is less than expected — refusing to credit', [
                    'topup_id' => $locked->id,
                    'expected_amount' => $expectedAmount,
                    'verified_amount' => $reportedAmount,
                ]);

                return;
            }

            $creditedNow = $this->creditVendor($vendorId, $expectedAmount, ['reference' => $locked->reference]);

            if ($creditedNow) {
                $locked->update([
                    'status' => 'completed',
                    'gateway_response' => $verification,
                ]);
                Cache::forget("wallet_topup:{$locked->reference}");
                Cache::forget("vendor:{$vendorId}:topups_available");
            }
        });

        if ($creditedNow) {
            $this->sendTopupNotifications($topup->fresh());
        }

        return $creditedNow || $alreadyCompleted;
    }

    /**
     * Best-effort, non-blocking notifications for a just-completed top-up.
     * Only ever called once per top-up — completeTopup() only invokes this
     * when THIS call performed the crediting, never for a racing caller that
     * found the top-up already completed.
     */
    private function sendTopupNotifications(WalletTopup $topup): void
    {
        try {
            $vendor = Vendor::find($topup->vendor_id);
            if (! $vendor) {
                return;
            }

            $amount = (float) $topup->amount;
            $reference = (string) $topup->reference;

            VendorNotification::create([
                'vendor_id' => $vendor->id,
                'type' => 'wallet_topup',
                'title' => 'Wallet topped up',
                'message' => 'Your wallet was topped up with GHS '.number_format($amount, 2).". Reference: {$reference}",
                'data' => [
                    'amount' => $amount,
                    'reference' => $reference,
                ],
            ]);

            AdminNotification::create([
                'type' => 'vendor_wallet_topup',
                'title' => 'Vendor wallet topped up',
                'message' => "Vendor {$vendor->name} topped up wallet with GHS ".number_format($amount, 2).". Reference: {$reference}",
                'vendor_id' => $vendor->id,
                'data' => [
                    'vendor_id' => $vendor->id,
                    'amount' => $amount,
                    'reference' => $reference,
                ],
            ]);

            try {
                \Illuminate\Support\Facades\Mail::to($vendor->email)->send(new \App\Mail\VendorWalletTopupMail($vendor, $amount, $reference));
            } catch (\Throwable $e) {
                Log::warning('Failed to send vendor topup email', ['err' => $e->getMessage(), 'vendor_id' => $vendor->id]);
            }

            $adminEmail = config('mail.admin_email') ?? env('ADMIN_EMAIL');
            if ($adminEmail) {
                try {
                    \Illuminate\Support\Facades\Mail::to($adminEmail)->send(new \App\Mail\AdminVendorWalletTopupMail($vendor, $amount, $reference));
                } catch (\Throwable $e) {
                    Log::warning('Failed to send admin topup email', ['err' => $e->getMessage()]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to create notifications for wallet topup', ['err' => $e->getMessage(), 'reference' => $topup->reference]);
        }
    }

    /**
     * Credit vendor wallet and create ledger entry
     */
    public function creditVendor(int $vendorId, float $amount, array $metadata = []): bool
    {
        $vendor = Vendor::find($vendorId);
        if (! $vendor) {
            return false;
        }

        $vendor->increment('wallet_balance', $amount);

        $balance = (float) $vendor->wallet_balance;

        WalletLedger::create([
            'vendor_id' => $vendorId,
            'type' => 'credit',
            'amount' => $amount,
            'balance_after' => $balance,
            'metadata' => $metadata,
        ]);

        return true;
    }

    /**
     * Debit vendor wallet and create ledger entry. Returns false if insufficient.
     */
    public function debitVendor(int $vendorId, float $amount, array $metadata = []): bool
    {
        $vendor = Vendor::find($vendorId);
        if (! $vendor) {
            return false;
        }

        $current = (float) $vendor->wallet_balance;
        if ($current < $amount) {
            return false;
        }

        $vendor->decrement('wallet_balance', $amount);

        $balance = (float) $vendor->wallet_balance;

        WalletLedger::create([
            'vendor_id' => $vendorId,
            'type' => 'debit',
            'amount' => $amount,
            'balance_after' => $balance,
            'metadata' => $metadata,
        ]);

        return true;
    }

    /**
     * Debit vendor wallet by consuming vendor top-up balances first.
     *
     * This method does not change the WalletTopup schema; instead it records
     * consumption amounts inside the WalletTopup.metadata JSON under the
     * `consumed` key. It updates the vendor wallet balance and creates a
     * ledger entry with details of which top-up records were consumed.
     */
    public function debitVendorFromTopups(int $vendorId, float $amount, array $metadata = []): bool
    {
        // Use a transaction and lock the vendor row to avoid race conditions
        return \Illuminate\Support\Facades\DB::transaction(function () use ($vendorId, $amount, $metadata) {
            $vendor = Vendor::whereKey($vendorId)->lockForUpdate()->first();
            if (! $vendor) {
                return false;
            }

            // Load only top-ups that still have available amount (amount > consumed)
            // Limit to 50 rows to keep memory bounded
            // Lock rows to prevent race conditions during concurrent debits
            $topups = \App\Models\WalletTopup::where('vendor_id', $vendorId)
                ->where('status', 'completed')
                ->whereColumn('amount', '>', 'consumed')
                ->orderBy('created_at')
                ->limit(50)
                ->lockForUpdate()
                ->get();

            $available = 0.0;
            foreach ($topups as $t) {
                $consumed = (float) ($t->consumed ?? 0.0);
                $available += max(0.0, $t->amount - $consumed);
            }

            if (round($available, 2) < round($amount, 2)) {
                return false;
            }

            $remaining = $amount;
            $consumptions = [];

            foreach ($topups as $t) {
                if ($remaining <= 0) {
                    break;
                }
                $consumedSoFar = (float) ($t->consumed ?? 0.0);
                $topupAvailable = max(0.0, $t->amount - $consumedSoFar);
                if ($topupAvailable <= 0) {
                    continue;
                }
                $take = min($topupAvailable, $remaining);
                $newConsumed = round($consumedSoFar + $take, 2);
                $t->consumed = $newConsumed;
                $t->save();

                $consumptions[] = ['topup_id' => $t->id, 'amount' => round($take, 2)];
                $remaining = round(max(0.0, $remaining - $take), 2);
            }

            // Decrement vendor wallet balance and write ledger entry
            $vendor->decrement('wallet_balance', $amount);
            $balance = (float) $vendor->wallet_balance;

            $ledgerMeta = array_merge($metadata, ['topup_consumptions' => $consumptions, 'from_topups' => true]);

            WalletLedger::create([
                'vendor_id' => $vendorId,
                'type' => 'debit',
                'amount' => $amount,
                'balance_after' => $balance,
                'metadata' => $ledgerMeta,
            ]);

            return true;
        });
    }

    /**
     * Reverse the vendor earnings paid out for an order (e.g. when an admin marks
     * it "Refunded"). Deducts every vendor who earned something from this order
     * (owner, reseller, or any vendor in a multi-level affiliate chain), based on
     * the `vendor_earning` already recorded per vendor in the `transactions` table.
     *
     * Idempotent: guarded by `orders.wallet_reversed_at`, so calling this more than
     * once for the same order (e.g. admin toggles status Refunded -> Pending ->
     * Refunded again) never double-deducts.
     *
     * All-or-nothing: if any involved vendor's wallet balance can't cover their
     * share, no vendor is debited and a RuntimeException is thrown so the caller
     * can abort the whole status change.
     *
     * @return array{already_reversed: bool, deductions: array<int, array{vendor_id: int, amount: float}>}
     */
    public function reverseOrderEarnings(Order $order, array $context = []): array
    {
        return DB::transaction(function () use ($order, $context) {
            $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($lockedOrder->wallet_reversed_at !== null) {
                return ['already_reversed' => true, 'deductions' => []];
            }

            $earningsByVendor = Transaction::where('order_id', $lockedOrder->id)
                ->where('vendor_earning', '>', 0)
                ->get()
                ->groupBy('vendor_id')
                ->map(fn ($rows) => round((float) $rows->sum('vendor_earning'), 2));

            if ($earningsByVendor->isEmpty()) {
                $lockedOrder->update(['wallet_reversed_at' => now()]);

                return ['already_reversed' => false, 'deductions' => []];
            }

            // Lock every involved vendor row in a consistent (ascending id) order
            // to avoid deadlocking against other concurrent wallet operations.
            $vendorIds = $earningsByVendor->keys()->sort()->values();
            $vendors = Vendor::whereIn('id', $vendorIds)->lockForUpdate()->get()->keyBy('id');

            foreach ($vendorIds as $vendorId) {
                $amount = $earningsByVendor[$vendorId];
                $vendor = $vendors->get($vendorId);

                if (! $vendor || (float) $vendor->wallet_balance < $amount) {
                    $name = $vendor?->name ?? "#{$vendorId}";

                    throw new \RuntimeException(
                        "Cannot refund order #{$lockedOrder->id}: vendor {$name} has insufficient wallet balance to cover GHS ".number_format($amount, 2).'.'
                    );
                }
            }

            $deductions = [];

            foreach ($vendorIds as $vendorId) {
                $amount = $earningsByVendor[$vendorId];
                $vendor = $vendors->get($vendorId);

                $vendor->decrement('wallet_balance', $amount);
                $vendor->refresh();

                WalletLedger::create([
                    'vendor_id' => $vendor->id,
                    'type' => 'debit',
                    'source' => 'order_refund',
                    'amount' => $amount,
                    'balance_after' => (float) $vendor->wallet_balance,
                    'metadata' => array_merge($context, [
                        'order_id' => $lockedOrder->id,
                        'action' => 'order_refunded',
                    ]),
                ]);

                VendorNotification::create([
                    'vendor_id' => $vendor->id,
                    'type' => VendorNotification::TYPE_ORDER_REFUNDED,
                    'title' => 'Order Refunded',
                    'message' => "Order #{$lockedOrder->id} was refunded. GHS ".number_format($amount, 2).' was deducted from your wallet.',
                    'order_id' => $lockedOrder->id,
                    'data' => [
                        'order_id' => $lockedOrder->id,
                        'amount' => $amount,
                    ],
                ]);

                $deductions[] = ['vendor_id' => $vendor->id, 'amount' => $amount];
            }

            $lockedOrder->update(['wallet_reversed_at' => now()]);

            return ['already_reversed' => false, 'deductions' => $deductions];
        });
    }

    /**
     * Compute withdrawable balance for a vendor.
     * Withdrawable balance = sum of credits with purpose = 'order_earning'
     * minus any debits that belong to withdrawals (type=debit and metadata.purpose = 'withdrawal' or similar).
     * We treat any debit ledger rows as reductions already applied.
     */
    public function getWithdrawableBalance(int $vendorId): float
    {
        // Simple, safe fallback: withdrawable = total wallet balance minus vendor top-ups.
        // This preserves previous UX (wallet balance shown as withdrawable) while
        // excluding top-up funds from withdrawable amounts as requested.
        $vendor = \App\Models\Vendor::find($vendorId);
        $totalBalance = $vendor?->wallet_balance ?? 0.0;

        $topups = $this->getVendorTopupsTotal($vendorId);

        $withdrawable = (float) $totalBalance - (float) $topups;

        return round(max(0.0, $withdrawable), 2);
    }

    /**
     * Compute total withdrawable balance across all vendors.
     *
     * Mirrors getWithdrawableBalance() per vendor:
     * withdrawable = max(0, wallet_balance - available_topups).
     */
    public function getTotalWithdrawableBalance(): float
    {
        $vendors = Vendor::query()->select(['id', 'wallet_balance'])->get();
        if ($vendors->isEmpty()) {
            return 0.0;
        }

        $topupsByVendor = \App\Models\WalletTopup::query()
            ->where('status', 'completed')
            ->selectRaw(
                'vendor_id, SUM(CASE WHEN (amount - COALESCE(consumed, 0)) > 0 THEN (amount - COALESCE(consumed, 0)) ELSE 0 END) as available'
            )
            ->groupBy('vendor_id')
            ->pluck('available', 'vendor_id');

        $totalWithdrawable = 0.0;
        foreach ($vendors as $vendor) {
            $availableTopups = (float) ($topupsByVendor[$vendor->id] ?? 0.0);
            // Match per-vendor /vendor/wallet display semantics: clamp and round each vendor first.
            $withdrawable = round(max(0.0, (float) $vendor->wallet_balance - $availableTopups), 2);
            $totalWithdrawable += $withdrawable;
        }

        return round($totalWithdrawable, 2);
    }

    /**
     * Sum total vendor top-ups (completed)
     * Top-ups are credit ledger entries with metadata.purpose = 'wallet_topup' and possibly status in metadata
     */
    public function getVendorTopupsTotal(int $vendorId): float
    {
        // Use the wallet_topups table as the authoritative source of top-ups.
        // Sum only the remaining (unconsumed) portions of completed top-ups.
        $total = \App\Models\WalletTopup::where('vendor_id', $vendorId)
            ->where('status', 'completed')
            ->selectRaw(
                'SUM(CASE WHEN (amount - COALESCE(consumed, 0)) > 0 THEN (amount - COALESCE(consumed, 0)) ELSE 0 END) as available'
            )
            ->value('available');

        return round(max(0.0, (float) $total), 2);
    }
}
