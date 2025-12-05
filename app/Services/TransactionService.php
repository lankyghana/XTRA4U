<?php
namespace App\Services;

use App\Models\Transaction;

class TransactionService
{
    /**
     * Get all transactions for a vendor.
     */
    public function getVendorTransactions($vendorId)
    {
        return Transaction::where('vendor_id', $vendorId)->get();
    }

    /**
     * Get total commissions for a vendor (only completed transactions).
     */
    public function getVendorCommissions($vendorId)
    {
        return Transaction::where('vendor_id', $vendorId)
            ->where('payment_status', 'completed')
            ->sum('commission_amount');
    }

    /**
     * Get total earnings for a vendor (only completed transactions).
     */
    public function getVendorEarnings($vendorId)
    {
        return Transaction::where('vendor_id', $vendorId)
            ->where('payment_status', 'completed')
            ->sum('vendor_earning');
    }
}
