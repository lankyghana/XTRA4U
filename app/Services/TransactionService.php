<?php
namespace App\Services;

use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class TransactionService
{
    /**
     * Get all transactions for a vendor.
     *
     * @deprecated This loads an unbounded dataset into memory and will not scale.
     *             Prefer vendorTransactionsQuery(), getVendorTransactionsPaginated(),
     *             or getVendorTransactionStatsByFilter().
     */
    public function getVendorTransactions($vendorId)
    {
        return Transaction::where('vendor_id', $vendorId)->get();
    }

    /**
     * Base query for a vendor's transactions.
     *
     * Returns a Builder so callers can add pagination/filters without loading
     * unbounded datasets into memory.
     */
    public function vendorTransactionsQuery(int $vendorId): Builder
    {
        return Transaction::query()->where('vendor_id', $vendorId);
    }

    /**
     * Scalable transaction list for UIs.
     */
    public function getVendorTransactionsPaginated(int $vendorId, int $perPage = 20)
    {
        return $this->vendorTransactionsQuery($vendorId)
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Scalable transaction list for dashboards (bounded).
     */
    public function getVendorTransactionsForDashboard(int $vendorId, int $limit = 50)
    {
        return $this->vendorTransactionsQuery($vendorId)
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * SQL-side aggregate stats for a vendor's transactions.
     *
     * Notes:
     * - Mirrors the historical controller behavior: only counts completed/successful
     *   transactions for earnings/sales/commissions.
     */
    public function getVendorTransactionStats(int $vendorId, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $query = $this->vendorTransactionsQuery($vendorId)
            ->whereIn('payment_status', ['completed', 'successful']);

        if ($from && $to) {
            $query->whereBetween('created_at', [$from, $to]);
        }

        $row = $query
            ->selectRaw('COALESCE(SUM(amount), 0) as sales')
            ->selectRaw('COALESCE(SUM(vendor_earning), 0) as earnings')
            ->selectRaw('COALESCE(SUM(commission_amount), 0) as commissions')
            ->first();

        return [
            'sales' => (float) ($row->sales ?? 0),
            'earnings' => (float) ($row->earnings ?? 0),
            'commissions' => (float) ($row->commissions ?? 0),
        ];
    }

    /**
     * Convenience helper mirroring the dashboard filter options.
     */
    public function getVendorTransactionStatsByFilter(int $vendorId, string $filter): array
    {
        $now = now();

        return match ($filter) {
            'today' => $this->getVendorTransactionStats($vendorId, $now->copy()->startOfDay(), $now->copy()->endOfDay()),
            'yesterday' => $this->getVendorTransactionStats($vendorId, $now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()),
            'this_week' => $this->getVendorTransactionStats($vendorId, $now->copy()->startOfWeek(), $now->copy()->endOfDay()),
            'this_month' => $this->getVendorTransactionStats($vendorId, $now->copy()->startOfMonth(), $now->copy()->endOfDay()),
            'all_time' => $this->getVendorTransactionStats($vendorId, null, null),
            default => $this->getVendorTransactionStats($vendorId, $now->copy()->startOfDay(), $now->copy()->endOfDay()),
        };
    }

    /**
     * Get total commissions for a vendor (only completed transactions).
     */
    public function getVendorCommissions($vendorId)
    {
        return Transaction::where('vendor_id', $vendorId)
            ->whereIn('payment_status', ['completed', 'successful'])
            ->sum('commission_amount');
    }

    /**
     * Get total earnings for a vendor (only completed transactions).
     */
    public function getVendorEarnings($vendorId)
    {
        return Transaction::where('vendor_id', $vendorId)
            ->whereIn('payment_status', ['completed', 'successful'])
            ->sum('vendor_earning');
    }
}
