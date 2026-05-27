<?php

namespace App\Services;

use App\Models\Vendor;
use App\Models\WalletLedger;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class WalletService
{
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
                if ($remaining <= 0) break;
                $consumedSoFar = (float) ($t->consumed ?? 0.0);
                $topupAvailable = max(0.0, $t->amount - $consumedSoFar);
                if ($topupAvailable <= 0) continue;
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
