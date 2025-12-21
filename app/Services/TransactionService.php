<?php
namespace App\Services;

use App\Models\Transaction;

class TransactionService
{
    /**
     * Get all transactions for a vendor.
     * UPDATED: Only returns successfully verified transactions
     */
    public function getVendorTransactions($vendorId)
    {
        return $this->successfulTransactionsQuery($vendorId)->get();
    }

    /**
     * Get total commissions for a vendor (only completed transactions).
     */
    public function getVendorCommissions($vendorId)
    {
        return $this->successfulTransactionsQuery($vendorId)->sum('commission_amount');
    }

    /**
     * Get total earnings for a vendor (only completed transactions).
     */
    public function getVendorEarnings($vendorId)
    {
        return $this->successfulTransactionsQuery($vendorId)->sum('vendor_earning');
    }

    /**
     * Base query for all successfully verified transactions.
     */
    protected function successfulTransactionsQuery($vendorId)
    {
        return Transaction::where('vendor_id', $vendorId)
            ->where('status', Transaction::STATUS_SUCCESSFUL);
    }
}
