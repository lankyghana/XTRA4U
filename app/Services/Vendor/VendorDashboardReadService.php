<?php

namespace App\Services\Vendor;

use App\Models\AfaRegistration;
use App\Models\Order;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\ResultCheckerOrder;
use App\Models\Transaction;
use App\Services\TransactionService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The vendor dashboard's READ logic, extracted unchanged from VendorDashboardController so that the dashboard pages and
 * the Vendor Assistant (M5.5) report the same figures from one source of truth.
 *
 * This class is a MOVE, not a redesign. Every query, status list, date boundary, fallback and rounding behaviour below
 * is what the controller did before the extraction (see DashboardCharacterizationTest, which was written against the
 * original controller). Nothing here writes. Nothing here defines a new financial formula; where the dashboard has a
 * quirk it is preserved and documented in docs/ai/04-vendor-assistant.md ("Known quirks"), not silently fixed.
 *
 * Identity is always an explicit vendor id supplied by trusted server code (the authenticated vendor, or the
 * ToolContext) — never derived from a request.
 */
class VendorDashboardReadService
{
    /** The dashboard's period filters, in display order. */
    public const PERIODS = ['today', 'yesterday', 'this_week', 'this_month', 'all_time'];

    public function __construct(private readonly TransactionService $transactions) {}

    // ------------------------------------------------------------------------------------------ sales / earnings

    /**
     * Sales, earnings and commissions for one dashboard filter. Any value that is not one of PERIODS behaves as
     * "today", exactly as the dashboard always did (both source calculations default to today).
     *
     * - sales / earnings: product-order transactions + AFA registrations + result checker orders, summed.
     * - commissions: product-order transactions ONLY. The dashboard has never reported a commission figure for AFA or
     *   result-checker sales, and this service does not invent one.
     *
     * @return array{
     *     sales: float, earnings: float, commissions: float,
     *     sources: array{
     *         product_orders: array{sales: float, earnings: float, commissions: float},
     *         afa: array{sales: float, earnings: float},
     *         result_checker: array{sales: float, earnings: float}
     *     }
     * }
     */
    public function salesSummary(int $vendorId, string $filter): array
    {
        $product = $this->transactions->getVendorTransactionStatsByFilter($vendorId, $filter);
        $afa = $this->afaStats($vendorId, $filter);
        $checker = $this->resultCheckerStats($vendorId, $filter);

        return [
            'sales' => $product['sales'] + $afa['sales'] + $checker['sales'],
            'earnings' => $product['earnings'] + $afa['earnings'] + $checker['earnings'],
            'commissions' => $product['commissions'],
            'sources' => [
                'product_orders' => $product,
                'afa' => $afa,
                'result_checker' => $checker,
            ],
        ];
    }

    /**
     * The window a filter covers; [null, null] means "all time". Unknown filters mean "today".
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    public function dateRange(string $filter): array
    {
        $now = now();

        return match ($filter) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            'this_week' => [$now->copy()->startOfWeek(), $now->copy()->endOfDay()],
            'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
            'all_time' => [null, null],
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
        };
    }

    /**
     * AFA earnings/sales for a vendor by date filter.
     *
     * - Vendor earnings come from: provider role = sum(vendor_earning) where vendor_id = vendor;
     *   reseller role = sum(reseller_earning) where reseller_vendor_id = vendor.
     * - Sales: provider = sum(vendor_price); reseller = sum(amount) - sum(vendor_price), floored at 0.
     *
     * @return array{sales: float, earnings: float}
     */
    public function afaStats(int $vendorId, string $filter): array
    {
        [$from, $to] = $this->dateRange($filter);

        $providerQuery = AfaRegistration::query()
            ->paid()
            ->where('vendor_id', $vendorId)
            ->whereNotNull('payment_completed_at');

        $resellerQuery = AfaRegistration::query()
            ->paid()
            ->where('reseller_vendor_id', $vendorId)
            ->whereNotNull('payment_completed_at');

        if ($from && $to) {
            $providerQuery->whereBetween('payment_completed_at', [$from, $to]);
            $resellerQuery->whereBetween('payment_completed_at', [$from, $to]);
        }

        $providerEarnings = (float) $providerQuery->sum('vendor_earning');
        $resellerEarnings = (float) $resellerQuery->sum('reseller_earning');
        $earnings = $providerEarnings + $resellerEarnings;

        $providerSales = (float) $providerQuery->sum('vendor_price');
        $resellerTotalSales = (float) $resellerQuery->sum('amount');
        $resellerBaseSales = (float) $resellerQuery->sum('vendor_price');
        $resellerSales = max($resellerTotalSales - $resellerBaseSales, 0);
        $sales = $providerSales + $resellerSales;

        return [
            'sales' => $sales,
            'earnings' => $earnings,
        ];
    }

    /**
     * Result-checker sales/earnings. Only `completed` orders count (profit is credited on completion), dated by
     * fulfilled_at.
     *
     * @return array{sales: float, earnings: float}
     */
    public function resultCheckerStats(int $vendorId, string $filter): array
    {
        [$from, $to] = $this->dateRange($filter);

        $query = ResultCheckerOrder::query()
            ->where('result_checker_orders.vendor_id', $vendorId)
            ->where('result_checker_orders.status', 'completed');

        if ($from && $to) {
            $query->whereBetween('result_checker_orders.fulfilled_at', [$from, $to]);
        }

        $sales = (float) $query->sum('result_checker_orders.total_price');

        // Earnings: SQL equivalent of ResultCheckerOrder::resolveVendorProfit().
        // Keep the COALESCE/CASE formula in sync with that method if pricing logic changes.
        $earningsQuery = clone $query;
        $earnings = (float) $earningsQuery
            ->join('network_services', 'result_checker_orders.service_id', '=', 'network_services.id')
            ->sum(DB::raw('
				COALESCE(result_checker_orders.vendor_profit, CASE
					WHEN (result_checker_orders.total_price - (result_checker_orders.quantity * network_services.base_price)) > 0
					THEN (result_checker_orders.total_price - (result_checker_orders.quantity * network_services.base_price))
					ELSE 0
				END)
			'));

        return [
            'sales' => $sales,
            'earnings' => $earnings,
        ];
    }

    // ------------------------------------------------------------------------------------------------- orders

    /**
     * The orders a vendor sees on its Orders page: the selling vendor's own orders, plus affiliate orders for products
     * the vendor owns (explicit owner_vendor_id on a reseller order, or - legacy rows - ownership derived through the
     * reseller listing). Only paid orders (payment_status paid|completed), and never `Pending` ones: the customer can
     * still track a Pending order on the storefront, but it stays out of the vendor's account until it moves past
     * Pending. Every other status (Processing, Verifying, On Hold, Completed, Cancelled, Failed, Refunded) is visible.
     *
     * Ownership is part of the query, so callers can add `where id = ?` and know a foreign order simply does not exist.
     *
     * @return Builder<Order>
     */
    public function visibleOrdersQuery(int $vendorId): Builder
    {
        return Order::query()
            ->where(function ($q) use ($vendorId) {
                // Selling vendor always sees their orders.
                $q->where('vendor_id', $vendorId)
                    // Owner vendor can also see affiliate orders for their products,
                    // but must not be able to update fulfillment status.
                    ->orWhere(function ($q2) use ($vendorId) {
                        $q2->where('owner_vendor_id', $vendorId)
                            ->where('is_reseller_order', true);
                    })
                    // Backward-compatible fallback: derive owner via reseller product mapping.
                    ->orWhere(function ($q2) use ($vendorId) {
                        $q2->where('is_reseller_order', true)
                            ->whereHas('resellerProduct', function ($q3) use ($vendorId) {
                                $q3->where('owner_vendor_id', $vendorId);
                            });
                    });
            })
            ->whereIn('payment_status', ['paid', 'completed'])
            ->whereNotIn('status', ['Pending']);
    }

    // ----------------------------------------------------------------------------------------------- products

    /**
     * Products the vendor created and owns (soft-deleted ones are excluded by the model's global scope).
     *
     * @return Builder<Product>
     */
    public function ownProductsQuery(int $vendorId): Builder
    {
        return Product::query()->where('vendor_id', $vendorId);
    }

    /**
     * Listings the vendor resells (other vendors' products with the vendor's own markup).
     *
     * @return Builder<ResellerProduct>
     */
    public function resellerListingsQuery(int $vendorId): Builder
    {
        return ResellerProduct::query()->where('reseller_vendor_id', $vendorId);
    }

    /**
     * The vendor's reseller listings that are actually for sale on its storefront (same rule as
     * listingIsPubliclyAvailable(), expressed as a query so it can be counted and paged).
     *
     * @return Builder<ResellerProduct>
     */
    public function publiclyAvailableListingsQuery(int $vendorId): Builder
    {
        return $this->resellerListingsQuery($vendorId)
            ->where('is_active', true)
            ->whereHas('product', fn ($p) => $p->where('is_active', true)->where('is_resellable', true));
    }

    /**
     * Catalogue head-counts for one vendor.
     *
     * @return array{own_active: int, own_inactive: int, listings_total: int, listings_available: int}
     */
    public function catalogueCounts(int $vendorId): array
    {
        $own = $this->ownProductsQuery($vendorId);

        return [
            'own_active' => (clone $own)->where('is_active', true)->count(),
            'own_inactive' => (clone $own)->where('is_active', false)->count(),
            'listings_total' => $this->resellerListingsQuery($vendorId)->count(),
            'listings_available' => $this->publiclyAvailableListingsQuery($vendorId)->count(),
        ];
    }

    /**
     * The vendor's own recorded transaction row for one order (the same table and columns the dashboard totals sum),
     * or null when none exists. Never another vendor's row: the vendor id is part of the query.
     *
     * @return array{sale_amount: float, commission: float, earning: float, payment_status: string}|null
     */
    public function orderEarningsRecord(int $vendorId, int $orderId): ?array
    {
        $row = Transaction::query()
            ->where('vendor_id', $vendorId)
            ->where('order_id', $orderId)
            ->select(['id', 'amount', 'commission_amount', 'vendor_earning', 'payment_status'])
            ->orderBy('id')
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'sale_amount' => (float) $row->amount,
            'commission' => (float) $row->commission_amount,
            'earning' => (float) $row->vendor_earning,
            'payment_status' => (string) $row->payment_status,
        ];
    }

    /**
     * Would this reseller listing appear on the vendor's public storefront? Mirrors StorefrontController::showVendorStore:
     * the listing is active AND its source product still exists (not soft-deleted), is active and is resellable.
     * The listing must have its `product` relation loaded.
     */
    public function listingIsPubliclyAvailable(ResellerProduct $listing): bool
    {
        $product = $listing->product;

        return $listing->is_active === true
            && $product !== null
            && $product->is_active === true
            && $product->is_resellable === true;
    }

    // --------------------------------------------------------------------------------------------- analytics

    /**
     * Everything the Analytics page shows, keyed by the view variable names it has always used.
     *
     * Quirks preserved on purpose: "revenue" and the 7-day trend count transactions with payment_status = 'completed'
     * only (the dashboard cards also accept 'successful'); the status breakdown counts orders the vendor is responsible
     * for WITHOUT a payment filter, so it includes unpaid Pending checkouts; "this week" here ends at end of week, not
     * end of today.
     *
     * @return array{
     *     totalOrders: int, ordersThisMonth: int, averageOrderValue: float, completionRate: float|int, salesTrend: list<array<string,mixed>>,
     *     topProducts: Collection<int,object>, statusBreakdown: array<string,int>,
     *     revenueToday: mixed, ordersToday: int, revenueThisWeek: mixed, ordersThisWeek: int, revenueThisMonth: mixed
     * }
     */
    public function analytics(int $vendorId): array
    {
        // Base query: orders where this vendor is involved (seller OR owner in affiliate orders)
        $allVendorOrdersQuery = Order::query()
            ->where(function ($q) use ($vendorId) {
                $q->where('vendor_id', $vendorId)
                    ->orWhere('owner_vendor_id', $vendorId);
            })
            ->whereIn('payment_status', ['paid', 'completed'])
            // Pending orders are excluded from vendor-side analytics, matching the orders list.
            ->whereNotIn('status', ['Pending']);

        // Total orders for this vendor
        $totalOrders = (clone $allVendorOrdersQuery)->count();
        $ordersThisMonth = (clone $allVendorOrdersQuery)
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();

        // Average earning per transaction (more accurate than order amount for vendors with mixed roles)
        $averageOrderValue = (float) Transaction::where('vendor_id', $vendorId)
            ->whereIn('payment_status', ['completed', 'successful'])
            ->avg('vendor_earning');

        // Completion rate based on orders this vendor is responsible for
        // For regular orders: vendor_id = this vendor
        // For affiliate orders where vendor is owner: owner_vendor_id = this vendor
        $responsibleOrdersQuery = Order::query()->where(function ($q) use ($vendorId) {
            $q->where(function ($q2) use ($vendorId) {
                $q2->where('vendor_id', $vendorId)->where('is_reseller_order', false);
            })->orWhere(function ($q2) use ($vendorId) {
                $q2->where('owner_vendor_id', $vendorId)->where('is_reseller_order', true);
            });
        });

        $totalResponsibleOrders = (clone $responsibleOrdersQuery)->count();
        $completedResponsibleOrders = (clone $responsibleOrdersQuery)->where('status', 'Completed')->count();
        $completionRate = $totalResponsibleOrders > 0 ? ($completedResponsibleOrders / $totalResponsibleOrders) * 100 : 0;

        // Sales trend (last 7 days) - use transactions for accurate vendor earnings
        $salesTrend = [];
        $maxAmount = 0;
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $amount = Transaction::where('vendor_id', $vendorId)
                ->whereDate('created_at', $date)
                ->where('payment_status', 'completed')
                ->sum('vendor_earning');
            $maxAmount = max($maxAmount, $amount);
            $salesTrend[] = [
                'date' => $date->format('M d'),
                'amount' => $amount,
                'percentage' => 0,
            ];
        }
        // Calculate percentages
        foreach ($salesTrend as &$day) {
            $day['percentage'] = $maxAmount > 0 ? ($day['amount'] / $maxAmount) * 100 : 0;
        }
        unset($day);

        // Top products - include both own products and affiliate products this vendor sold
        $ownProductSales = Order::where('vendor_id', $vendorId)
            ->where('is_reseller_order', false)
            ->whereNotIn('status', ['Cancelled', 'Failed'])
            ->selectRaw('service_purchased as name, COUNT(*) as orders_count, SUM(amount_paid) as total_sales')
            ->groupBy('service_purchased');

        $affiliateProductSales = Order::where('owner_vendor_id', $vendorId)
            ->where('is_reseller_order', true)
            ->whereNotIn('status', ['Cancelled', 'Failed'])
            ->selectRaw('service_purchased as name, COUNT(*) as orders_count, SUM(owner_earning) as total_sales')
            ->groupBy('service_purchased');

        // Also include reseller products this vendor sold (as reseller)
        $resellerProductSales = Order::where('vendor_id', $vendorId)
            ->where('is_reseller_order', true)
            ->whereNotIn('status', ['Cancelled', 'Failed'])
            ->selectRaw('service_purchased as name, COUNT(*) as orders_count, SUM(reseller_earning) as total_sales')
            ->groupBy('service_purchased');

        $union = $ownProductSales
            ->unionAll($affiliateProductSales)
            ->unionAll($resellerProductSales);

        $topProducts = DB::query()
            ->fromSub($union, 'product_sales')
            ->selectRaw('name, SUM(orders_count) as orders_count, SUM(total_sales) as total_sales')
            ->groupBy('name')
            ->orderByDesc('orders_count')
            ->limit(5)
            ->get()
            ->map(fn ($row) => (object) [
                'name' => $row->name,
                'orders_count' => (int) $row->orders_count,
                'total_sales' => (float) $row->total_sales,
            ]);

        // Status breakdown for orders this vendor is responsible for (SQL-side)
        $rawStatusCounts = (clone $responsibleOrdersQuery)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $statusBreakdown = [
            'pending' => (int) ($rawStatusCounts['Pending'] ?? 0),
            'processing' => (int) ($rawStatusCounts['Processing'] ?? 0),
            'completed' => (int) ($rawStatusCounts['Completed'] ?? 0),
            'failed' => (int) ($rawStatusCounts['Failed'] ?? 0),
            'cancelled' => (int) ($rawStatusCounts['Cancelled'] ?? 0),
        ];

        // Revenue summaries - use transactions for accurate vendor-specific earnings
        $revenueToday = Transaction::where('vendor_id', $vendorId)
            ->whereDate('created_at', now())
            ->where('payment_status', 'completed')
            ->sum('vendor_earning');
        $ordersToday = (clone $allVendorOrdersQuery)->whereDate('created_at', now())->count();

        $revenueThisWeek = Transaction::where('vendor_id', $vendorId)
            ->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->where('payment_status', 'completed')
            ->sum('vendor_earning');
        $ordersThisWeek = (clone $allVendorOrdersQuery)
            ->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->count();

        $revenueThisMonth = Transaction::where('vendor_id', $vendorId)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->where('payment_status', 'completed')
            ->sum('vendor_earning');

        return compact(
            'totalOrders',
            'ordersThisMonth',
            'averageOrderValue',
            'completionRate',
            'salesTrend',
            'topProducts',
            'statusBreakdown',
            'revenueToday',
            'ordersToday',
            'revenueThisWeek',
            'ordersThisWeek',
            'revenueThisMonth'
        );
    }
}
