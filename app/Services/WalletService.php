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
        $vendor = Vendor::find($vendorId);
        if (! $vendor) {
            return false;
        }

        // Load completed top-ups ordered by oldest first (FIFO consumption)
        $topups = \App\Models\WalletTopup::where('vendor_id', $vendorId)
            ->where('status', 'completed')
            ->orderBy('created_at')
            ->get();

        $available = 0.0;
        foreach ($topups as $t) {
            $consumed = (float) data_get($t->metadata, 'consumed', 0.0);
            $available += max(0.0, $t->amount - $consumed);
        }

        if (round($available, 2) < round($amount, 2)) {
            return false;
        }

        $remaining = $amount;
        $consumptions = [];

        foreach ($topups as $t) {
            if ($remaining <= 0) break;
            $consumedSoFar = (float) data_get($t->metadata, 'consumed', 0.0);
            $topupAvailable = max(0.0, $t->amount - $consumedSoFar);
            if ($topupAvailable <= 0) continue;
            $take = min($topupAvailable, $remaining);
            $newConsumed = $consumedSoFar + $take;
            $meta = $t->metadata ?? [];
            $meta['consumed'] = $newConsumed;
            $t->metadata = $meta;
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
     * Sum total vendor top-ups (completed)
     * Top-ups are credit ledger entries with metadata.purpose = 'wallet_topup' and possibly status in metadata
     */
    public function getVendorTopupsTotal(int $vendorId): float
    {
        // Use the wallet_topups table as the authoritative source of top-ups.
        $total = \App\Models\WalletTopup::where('vendor_id', $vendorId)
            ->where('status', 'completed')
            ->sum('amount');

        return round((float) $total, 2);
    }
}
