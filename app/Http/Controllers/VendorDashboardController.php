<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Models\VendorNotification;
use App\Models\VendorWithdrawal;
use App\Models\AdminNotification;
use App\Models\AfaRegistration;
use App\Models\ResultCheckerOrder;
use App\Mail\VendorWithdrawalMail;
use App\Services\AffiliateChainService;
use App\Services\SmsService;
use App\Services\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VendorDashboardController extends Controller
{
	protected $transactionService;

	public function __construct(TransactionService $transactionService)
	{
		$this->transactionService = $transactionService;
	}

	public function index()
	{
		$vendor = $this->resolveVendor();
		$vendorId = $vendor->id;

		// Dashboard must not load unbounded datasets.
		// Keep a bounded recent list for any UI components that may display transactions.
		$transactions = $this->transactionService->getVendorTransactionsForDashboard($vendorId, 50);

		// Only count completed transactions for sales/earnings (all time for withdrawable balance)
		$transactionAllTimeStats = $this->transactionService->getVendorTransactionStatsByFilter($vendorId, 'all_time');
		$transactionAllTimeEarnings = (float) $transactionAllTimeStats['earnings'];
		$afaAllTimeStats = $this->getAfaFilteredStats($vendorId, 'all_time');
		$allTimeEarnings = $transactionAllTimeEarnings + $afaAllTimeStats['earnings'];
		
		// Default to "today" filter for display
		$filter = request('filter', 'today');
		$filteredStats = $this->transactionService->getVendorTransactionStatsByFilter($vendorId, $filter);
		$afaFilteredStats = $this->getAfaFilteredStats($vendorId, $filter);
		$totalSales = $filteredStats['sales'] + $afaFilteredStats['sales'];
		$totalEarnings = $filteredStats['earnings'] + $afaFilteredStats['earnings'];
		$commissions = $filteredStats['commissions'];
		
		// Vendors must only see successfully-paid orders.
		$productOrders = Order::query()
			->where(function ($q) use ($vendorId) {
				$q->where('vendor_id', $vendorId)
					->orWhere('owner_vendor_id', $vendorId)
					// Backward-compatible fallback: some legacy rows may not have owner_vendor_id,
					// but reseller_product_id still points to the owning vendor.
					->orWhereHas('resellerProduct', function ($q2) use ($vendorId) {
						$q2->where('owner_vendor_id', $vendorId);
					});
			})
			->whereIn('payment_status', ['paid', 'completed'])
			->whereIn('status', ['Processing', 'Completed'])
			->whereHas('transactions', function ($q) {
				$q->whereIn('payment_status', ['successful', 'completed']);
			})
			->with(['service'])
			->latest()
			->limit(50)
			->get();

		// Surface paid AFA registrations in the same recent list shown on dashboard.
		$afaOrders = $this->getRecentAfaRegistrations($vendorId, 50);

		$orders = $productOrders
			->concat($afaOrders)
			->sortByDesc(fn ($row) => $row->created_at?->getTimestamp() ?? 0)
			->take(50)
			->values();
		$products = Product::where('vendor_id', $vendorId)->orderBy('name')->get();
		$storeLink = route('storefront.vendor', ['vendor' => $vendor]);
		$recentWithdrawals = VendorWithdrawal::where('vendor_id', $vendorId)->latest()->take(5)->get();
		$withdrawableBalance = $this->calculateWithdrawableBalance($vendorId, $allTimeEarnings);

		// Results Checker Stats
		$resultCheckerOrdersToday = ResultCheckerOrder::where('vendor_id', $vendorId)
			->whereDate('created_at', now()->toDateString())
			->count();
		$resultCheckerRevenueToday = ResultCheckerOrder::where('vendor_id', $vendorId)
			->whereDate('created_at', now()->toDateString())
			->whereIn('status', ['completed', 'pending_payment'])
			->sum('total_price');
		$resultCheckerCompletedToday = ResultCheckerOrder::where('vendor_id', $vendorId)
			->whereDate('created_at', now()->toDateString())
			->where('status', 'completed')
			->count();
		$resultCheckerPendingStock = ResultCheckerOrder::where('vendor_id', $vendorId)
			->where('status', 'pending_stock')
			->count();

		return view('vendor.dashboard', compact(
			'vendor',
			'orders',
			'products',
			'totalSales',
			'totalEarnings',
			'storeLink',
			'transactions',
			'commissions',
			'recentWithdrawals',
			'withdrawableBalance',
			'filter',
			'resultCheckerOrdersToday',
			'resultCheckerRevenueToday',
			'resultCheckerCompletedToday',
			'resultCheckerPendingStock'
		));
	}

	/**
	 * Get filtered sales stats via AJAX
	 */
	public function getFilteredSalesStats()
	{
		$vendor = $this->resolveVendor();
		$vendorId = $vendor->id;
		
		$filter = request('filter', 'today');
		$stats = $this->transactionService->getVendorTransactionStatsByFilter($vendorId, $filter);
		$afaStats = $this->getAfaFilteredStats($vendorId, $filter);
		
		return response()->json([
			'sales' => number_format($stats['sales'] + $afaStats['sales'], 2),
			'earnings' => number_format($stats['earnings'] + $afaStats['earnings'], 2),
			'filter' => $filter,
		]);
	}

	/**
	 * Get stats filtered by date range
	 */
	/**
	 * @deprecated Dashboard stats are computed via SQL in TransactionService.
	 *             This method is kept only to preserve backward compatibility
	 *             if referenced elsewhere.
	 */
	private function getFilteredStats($transactions, string $filter): array
	{
		$now = now();

		$filtered = match($filter) {
			'today' => $transactions->filter(fn($t) => $t->created_at->isToday()),
			'yesterday' => $transactions->filter(fn($t) => $t->created_at->isYesterday()),
			'this_week' => $transactions->filter(fn($t) => $t->created_at->greaterThanOrEqualTo($now->copy()->startOfWeek())),
			'this_month' => $transactions->filter(fn($t) => $t->created_at->greaterThanOrEqualTo($now->copy()->startOfMonth())),
			'all_time' => $transactions,
			default => $transactions->filter(fn($t) => $t->created_at->isToday()),
		};

		return [
			'sales' => $filtered->sum('amount'),
			'earnings' => $filtered->sum('vendor_earning'),
			'commissions' => $filtered->sum('commission_amount'),
		];
	}

	/**
	 * Get AFA earnings/sales for vendor by date filter.
	 *
	 * Notes:
	 * - Vendor earnings come from:
	 *   - source/provider role: sum(vendor_earning) where vendor_id = vendor
	 *   - reseller role: sum(reseller_earning) where reseller_vendor_id = vendor
	 * - For sales, we mirror the transaction pattern (amount attributable to that vendor):
	 *   - provider sales: sum(vendor_price)
	 *   - reseller sales: sum(amount - vendor_price) (computed as sum(amount) - sum(vendor_price))
	 */
	private function getAfaFilteredStats(int $vendorId, string $filter): array
	{
		[$from, $to] = $this->resolveDateRange($filter);

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

	private function getRecentAfaRegistrations(int $vendorId, int $limit = 50)
	{
		return AfaRegistration::query()
			->paid()
			->where(function ($q) use ($vendorId) {
				$q->where('reseller_vendor_id', $vendorId)
					->orWhere(function ($qq) use ($vendorId) {
						$qq->where('vendor_id', $vendorId)
							->where('is_reseller_order', false);
					});
			})
			->latest()
			->limit($limit)
			->get();
	}

	private function resolveDateRange(string $filter): array
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

	public function orders(Request $request)
	{
		$vendor = $this->resolveVendor();
		$search = trim((string) $request->query('q', ''));
		$search = ltrim($search, "# \t\n\r\0\x0B");
		$search = mb_substr($search, 0, 100);
		$isNumericSearch = $search !== '' && ctype_digit($search);
		$like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';

		$orders = Order::query()
			->where(function ($q) use ($vendor) {
				// Selling vendor always sees their orders.
				$q->where('vendor_id', $vendor->id)
					// Owner vendor can also see affiliate orders for their products,
					// but must not be able to update fulfillment status.
					->orWhere(function ($q2) use ($vendor) {
						$q2->where('owner_vendor_id', $vendor->id)
							->where('is_reseller_order', true);
					})
					// Backward-compatible fallback: derive owner via reseller product mapping.
					->orWhere(function ($q2) use ($vendor) {
						$q2->where('is_reseller_order', true)
							->whereHas('resellerProduct', function ($q3) use ($vendor) {
								$q3->where('owner_vendor_id', $vendor->id);
							});
					});
			})
			->whereIn('payment_status', ['paid', 'completed'])
			->whereIn('status', ['Processing', 'Completed'])
			->whereHas('transactions', fn ($q) => $q->whereIn('payment_status', ['successful', 'completed']))
			->when($search !== '', function ($q) use ($search, $isNumericSearch, $like) {
				$q->where(function ($q2) use ($search, $isNumericSearch, $like) {
					if ($isNumericSearch) {
						$q2->orWhere('id', (int) $search);
					}

					$q2->orWhere('recipient_phone_number', 'like', $like)
						->orWhere('mobile_money_number', 'like', $like)
						->orWhere('payment_reference', 'like', $like)
						->orWhere('service_purchased', 'like', $like)
						->orWhereHas('service', function ($q3) use ($like) {
							$q3->where('name', 'like', $like);
						});
				});
			})
			->with(['service', 'resellerVendor'])
			->latest()
			->paginate(20)
			->withQueryString();

		return view('vendor.orders.index', compact('vendor', 'orders'));
	}

	/**
	 * Show affiliate orders - orders where this vendor is the product owner
	 * but the product was sold by a reseller
	 */
	public function affiliateOrders(Request $request)
	{
		$vendor = $this->resolveVendor();
		$search = trim((string) $request->query('q', ''));
		$search = ltrim($search, "# \t\n\r\0\x0B");
		$search = mb_substr($search, 0, 100);
		$isNumericSearch = $search !== '' && ctype_digit($search);
		$like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';

		// Affiliate Orders tab is for the selling vendor (reseller) to track
		// affiliate sales they must fulfill.
		$orders = Order::query()
			->where('vendor_id', $vendor->id)
			->where('is_reseller_order', true)
			->whereIn('payment_status', ['paid', 'completed'])
			->whereIn('status', ['Processing', 'Completed'])
			->whereHas('transactions', fn ($q) => $q->whereIn('payment_status', ['successful', 'completed']))
			->when($search !== '', function ($q) use ($search, $isNumericSearch, $like) {
				$q->where(function ($q2) use ($search, $isNumericSearch, $like) {
					if ($isNumericSearch) {
						$q2->orWhere('id', (int) $search);
					}

					$q2->orWhere('recipient_phone_number', 'like', $like)
						->orWhere('mobile_money_number', 'like', $like)
						->orWhere('payment_reference', 'like', $like)
						->orWhere('service_purchased', 'like', $like)
						->orWhereHas('service', function ($q3) use ($like) {
							$q3->where('name', 'like', $like);
						});
				});
			})
			->with(['ownerVendor', 'service', 'resellerProduct.ownerVendor'])
			->latest()
			->paginate(20)
			->withQueryString();

		return view('vendor.orders.affiliate', compact('vendor', 'orders'));
	}
    
	public function affiliates()
	{
		$vendor = $this->resolveVendor();
		$referrals = Vendor::where('affiliate_vendor_id', $vendor->id)->latest()->paginate(20);
		$totalReferrals = Vendor::where('affiliate_vendor_id', $vendor->id)->count();
		$activeReferrals = Vendor::where('affiliate_vendor_id', $vendor->id)->where('is_approved', true)->count();
		
		// Get affiliate parent if connected
		$affiliateParent = $vendor->affiliate_vendor_id 
			? Vendor::find($vendor->affiliate_vendor_id) 
			: null;

		return view('vendor.affiliates.index', compact('vendor', 'referrals', 'totalReferrals', 'activeReferrals', 'affiliateParent'));
	}

	public function joinAffiliate(Request $request)
	{
		$vendor = $this->resolveVendor();
		
		// Check if already connected to an affiliate
		if ($vendor->affiliate_vendor_id) {
			return back()->with('affiliate_error', 'You are already connected to an affiliate parent.');
		}
		
		$request->validate([
			'affiliate_code' => 'required|string|max:20',
		]);
		
		$affiliateCode = strtoupper(trim($request->affiliate_code));
		
		// Find the affiliate parent by vendor code
		$affiliateParent = Vendor::where('vendor_code', $affiliateCode)
			->where('is_approved', true)
			->first();
		
		if (!$affiliateParent) {
			return back()->with('affiliate_error', 'Invalid affiliate code. Please check and try again.');
		}
		
		// Cannot affiliate with yourself
		if ($affiliateParent->id === $vendor->id) {
			return back()->with('affiliate_error', 'You cannot connect to your own affiliate code.');
		}
		
		// Update vendor with affiliate parent
		$vendor->affiliate_vendor_id = $affiliateParent->id;
		$vendor->save();
		
		return back()->with('affiliate_success', 'Successfully connected to ' . $affiliateParent->business_name . '! You can now browse and resell their products.');
	}

	public function analytics()
	{
		$vendor = $this->resolveVendor();
		$vendorId = $vendor->id;

		// Base query: orders where this vendor is involved (seller OR owner in affiliate orders)
		$allVendorOrdersQuery = Order::query()
			->where(function ($q) use ($vendorId) {
				$q->where('vendor_id', $vendorId)
					->orWhere('owner_vendor_id', $vendorId);
			})
			->whereIn('payment_status', ['paid', 'completed'])
			->whereIn('status', ['Processing', 'Completed'])
			->whereHas('transactions', fn ($q) => $q->whereIn('payment_status', ['successful', 'completed']));

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
				'percentage' => 0
			];
		}
		// Calculate percentages
		foreach ($salesTrend as &$day) {
			$day['percentage'] = $maxAmount > 0 ? ($day['amount'] / $maxAmount) * 100 : 0;
		}

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

		$topProducts = \Illuminate\Support\Facades\DB::query()
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

		return view('vendor.analytics.index', compact(
			'vendor',
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
		));
	}

	public function updateOrderStatus(Request $request, Order $order)
	{
		$vendor = $this->resolveVendor();
		
		// Check authorization:
		// - Regular orders: selling vendor (vendor_id) fulfills
		// - Reseller orders: product owner (owner_vendor_id) fulfills; reseller is read-only
		$isResellerOrder = (bool) $order->is_reseller_order;
		$canUpdateStatus = $isResellerOrder
			? ((int) $order->owner_vendor_id === (int) $vendor->id)
			: ((int) $order->vendor_id === (int) $vendor->id);

		if (! $canUpdateStatus) {
			$message = $isResellerOrder
				? 'You can\'t update this affiliate order. Only the product owner can change the status.'
				: 'You can\'t update this order. Only the selling vendor can change the status.';

			if ($request->expectsJson()) {
				return response()->json([
					'message' => $message,
				], 403);
			}

			return redirect()->route('vendor.orders.index')->with('error', $message);
		}

		$request->validate([
			'status' => ['required', 'in:Pending,Processing,Completed,Cancelled'],
		]);

		// Don't allow changing status from Completed or Cancelled
		if ($order->status === 'Completed' || $order->status === 'Cancelled') {
			return back()->with('error', 'Cannot modify a completed or cancelled order.');
		}

		$previousStatus = $order->status;

		$order->update([
			'status' => $request->status,
		]);

		// Update transaction payment_status to match order status
		$transactionStatus = match($request->status) {
			'Completed' => 'completed',
			'Cancelled', 'Failed' => 'failed',
			default => 'pending',
		};
		
		// Update all transactions for this order
		Transaction::where('order_id', $order->id)->update([
			'payment_status' => $transactionStatus,
		]);

		// Send SMS to recipient when order is marked as Completed
		if ($request->status === 'Completed' && $previousStatus !== 'Completed') {
			try {
				$smsService = app(SmsService::class);
				$smsService->sendOrderCompletedSms(
					$order->recipient_phone_number,
					$order->service_purchased,
					$order->id
				);
			} catch (\Exception $e) {
				Log::error('Failed to send order completion SMS', [
					'order_id' => $order->id,
					'error' => $e->getMessage(),
				]);
			}
		}

		return back()->with('success', 'Order status updated successfully.');
	}

	public function requestWithdrawal(Request $request)
	{
		$vendor = $this->resolveVendor();
		$vendorId = $vendor->id;
		$walletService = app(\App\Services\WalletService::class);
		$withdrawableBalance = $walletService->getWithdrawableBalance($vendor->id);

		$withdrawalNetworks = array_keys((array) config('momo.withdrawal_networks', []));
		$withdrawalNetworkLabels = collect((array) config('momo.withdrawal_networks', []))
			->map(fn ($network, $key) => $network['label'] ?? $key)
			->values()
			->implode(', ');

		$data = $request->validate([
			'withdraw_amount' => ['required', 'numeric', 'min:1'],
			'momo_number' => ['required', 'string', 'regex:/^0[235][0-9]{8}$/'],
			'momo_network' => ['required', 'string', Rule::in($withdrawalNetworks)],
			'momo_account_name' => ['nullable', 'string', 'max:120'],
			'momo_account_type' => ['nullable', 'string', 'max:50'],
			'notes' => ['nullable', 'string', 'max:255'],
		], [
			'momo_number.regex' => 'Please enter a valid Ghana mobile number (e.g., 0241234567).',
			'momo_network.in' => 'Please select a valid network (' . $withdrawalNetworkLabels . ').',
		]);

		if ($data['withdraw_amount'] > $withdrawableBalance) {
			return back()->withErrors([
				'withdraw_amount' => 'Requested amount exceeds your withdrawable balance.',
			])->withInput();
		}

		$amount = (float) $data['withdraw_amount'];

		$withdrawal = DB::transaction(function () use ($vendor, $vendorId, $data, $amount, $walletService) {
			$lockedVendor = Vendor::whereKey($vendorId)->lockForUpdate()->firstOrFail();

			$gatewayManager = app(\App\Services\GatewayManager::class);
			$gateway = $gatewayManager->getDefaultConfig(\App\Models\PaymentGatewayConfig::TYPE_PAYOUT);

			if (!$gateway || !$gateway->is_active || !$gateway->supports_payout || !$gateway->isConfigured()) {
				throw \Illuminate\Validation\ValidationException::withMessages([
					'withdraw_amount' => 'Withdrawals are temporarily unavailable: no active payout gateway is configured.',
				]);
			}

			$lockedWithdrawable = $walletService->getWithdrawableBalance($lockedVendor->id);
			if (round($lockedWithdrawable, 2) < round($amount, 2)) {
				throw ValidationException::withMessages([
					'withdraw_amount' => 'Insufficient withdrawable balance.',
				]);
			}

			$lockedVendor->decrement('wallet_balance', $amount);

			$create = [
				'vendor_id' => $vendorId,
				'amount' => $amount,
				'momo_number' => $data['momo_number'],
				'momo_network' => $data['momo_network'],
				'payout_gateway' => $gateway?->gateway_name,
				'status' => VendorWithdrawal::STATUS_PROCESSING,
				'reference' => 'WD-' . Str::upper(Str::random(12)),
				'notes' => $data['notes'] ?? null,
			];

			// Backward-compatible: don't write new columns if migration hasn't run yet.
			if (Schema::hasColumn('vendor_withdrawals', 'momo_account_name')) {
				$create['momo_account_name'] = $data['momo_account_name'] ?? null;
			}
			if (Schema::hasColumn('vendor_withdrawals', 'momo_account_type')) {
				$create['momo_account_type'] = $data['momo_account_type'] ?? null;
			}

			return VendorWithdrawal::create($create);
		});

		// Queue a payout job (preferred) and also attempt an immediate sync run.
		// This improves UX on hosts where queue workers/scheduler are not always running.
		\App\Jobs\ProcessVendorWithdrawalPayout::dispatch($withdrawal->id)->afterCommit();
		try {
			\App\Jobs\ProcessVendorWithdrawalPayout::dispatchSync($withdrawal->id);
		} catch (\Throwable $e) {
			Log::warning('Immediate withdrawal payout attempt failed; queued job will retry', [
				'withdrawal_id' => $withdrawal->id,
				'error' => $e->getMessage(),
			]);
		}

		try {
			if (!empty($vendor->email)) {
				Mail::to($vendor->email)->send(new VendorWithdrawalMail(
					$withdrawal,
					$vendor,
					'requested'
				));
			}
		} catch (\Throwable $e) {
			Log::warning('Failed to send withdrawal request email', [
				'withdrawal_id' => $withdrawal->id,
				'error' => $e->getMessage(),
			]);
		}

		return back()->with('status', 'Withdrawal request received. Your payout is being processed automatically.');
	}

	public function lookupWithdrawalAccountName(Request $request)
	{
		$this->resolveVendor();

		$data = $request->validate([
			'momo_number' => ['required', 'string', 'regex:/^0[235][0-9]{8}$/'],
		]);

		$gatewayManager = app(\App\Services\GatewayManager::class);
		$payoutService = $gatewayManager->getPayoutService();

		if (!$payoutService || !($payoutService instanceof \App\Services\Payouts\BulkClixPayoutService)) {
			return response()->json([
				'success' => false,
				'name' => null,
				'message' => 'Account name lookup is not available for the current payout gateway.',
			]);
		}

		$result = $payoutService->lookupMsisdnName((string) $data['momo_number']);

		return response()->json([
			'success' => (bool) ($result['success'] ?? false),
			'name' => $result['name'] ?? null,
			'message' => $result['message'] ?? null,
		]);
	}



	public function withdrawals()
	{
		$vendor = $this->resolveVendor();
		$vendorId = $vendor->id;

		$walletService = app(\App\Services\WalletService::class);
		// Total wallet balance (spendable for orders) remains the vendor wallet balance
		$totalBalance = (float) $vendor->wallet_balance;
		// Withdrawable balance should include only sales earnings (order_earning) minus processed withdrawal debits
		$withdrawableBalance = $walletService->getWithdrawableBalance($vendorId);
		// Vendor top-ups total (display-only)
		$vendorTopupsTotal = $walletService->getVendorTopupsTotal($vendorId);
		$withdrawals = VendorWithdrawal::where('vendor_id', $vendorId)
			->latest()
			->paginate(10);
		$pendingTotal = VendorWithdrawal::where('vendor_id', $vendorId)
			->where('status', VendorWithdrawal::STATUS_PROCESSING)
			->sum('amount');
		$approvedTotal = VendorWithdrawal::where('vendor_id', $vendorId)
			->where('status', VendorWithdrawal::STATUS_APPROVED)
			->sum('amount');

		// Also prepare unified wallet history for the UI (read-only view).
		$ledgerEntries = \App\Models\WalletLedger::where('vendor_id', $vendorId)->orderByDesc('created_at')->get();

		// Top-ups metrics for the Top Ups tab (display-only)
		$totalTopups = \App\Models\WalletTopup::where('vendor_id', $vendorId)
			->where('status', 'completed')
			->selectRaw('SUM(amount - COALESCE(consumed, 0)) as available')
			->value('available');
		$totalTopups = max(0.0, (float) $totalTopups);

		$topupsSpent = \App\Models\WalletLedger::where('vendor_id', $vendorId)
			->where('type', 'debit')
			->where('metadata->from_topups', true)
			->sum('amount');

		$topupOrdersCount = \App\Models\Order::where('vendor_id', $vendorId)
			->where('payment_source', 'wallet')
			->count();

		$topupOrdersTotal = \App\Models\Order::where('vendor_id', $vendorId)
			->where('payment_source', 'wallet')
			->sum('amount_paid');

		// Normalize ledger entries
		$ledgerItems = $ledgerEntries->map(function ($e) {
			return (object) [
				'type' => $e->type === 'credit' ? ($e->metadata['purpose'] ?? 'Top-up') : 'Withdrawal',
				'amount' => $e->amount,
				'status' => 'completed',
				'reference' => $e->metadata['reference'] ?? null,
				'date' => $e->created_at,
			];
		});

		// Normalize withdrawal records
		$withdrawalItems = $withdrawals->getCollection()->map(function ($w) {
			return (object) [
				'type' => 'Withdrawal',
				'amount' => $w->amount,
				'status' => $w->status,
				'reference' => $w->reference,
				'date' => $w->created_at,
			];
		});

		$history = $ledgerItems->concat($withdrawalItems)->sortByDesc('date')->values();

		return view('vendor.withdrawals.index', compact(
			'vendor',
			'withdrawals',
			'totalBalance',
			'withdrawableBalance',
			'vendorTopupsTotal',
			'pendingTotal',
			'approvedTotal',
			'history',
			'totalTopups',
			'topupsSpent',
			'topupOrdersCount',
			'topupOrdersTotal'
		));
	}

	private function resolveVendor(): Vendor
	{
		$vendorGuardUser = Auth::guard('vendor')->user();
		$user = Auth::user();

		// Do not use `$user->vendor` here.
		// That relationship expects a `vendors.user_id` column which may not exist.
		$vendor = $vendorGuardUser instanceof Vendor
			? $vendorGuardUser
			: ($user instanceof Vendor ? $user : null);

		abort_unless($vendor, 403, 'Vendor account required.');

		return $vendor;
	}

	// Marketplace - Browse products from affiliate parent to resell
	public function marketplace()
	{
		$vendor = $this->resolveVendor();
		$chainService = new AffiliateChainService();
		
		// Get products from the vendor's affiliate parent
		if (!$vendor->affiliate_vendor_id) {
			return view('vendor.marketplace.index', [
				'vendor' => $vendor,
				'products' => collect(),
				'hasAffiliateParent' => false,
				'networkServices' => collect(),
			]);
		}

		$affiliateParent = Vendor::find($vendor->affiliate_vendor_id);

		// Products available to resell from the affiliate parent:
		// - Products the parent owns
		// - Products the parent is reselling (enables multi-level reseller chains)
		$ownedProducts = Product::where('vendor_id', $affiliateParent->id)
			->where('is_active', true)
			->where('is_resellable', true)
			->get();

		$parentResellerListings = ResellerProduct::where('reseller_vendor_id', $affiliateParent->id)
			->where('is_active', true)
			->with('product')
			->get();

		$parentResellProducts = $parentResellerListings
			->pluck('product')
			->filter(function ($p) {
				return $p && $p->is_active && $p->is_resellable;
			});

		$allProducts = $ownedProducts
			->merge($parentResellProducts)
			->unique('id')
			->values();

		$perPage = 12;
		$page = \Illuminate\Pagination\LengthAwarePaginator::resolveCurrentPage();
		$pageItems = $allProducts->slice(($page - 1) * $perPage, $perPage)->values();
		$productIds = $pageItems->pluck('id')->all();

		$resellerProductsForVendor = ResellerProduct::whereIn('product_id', $productIds)
			->where('reseller_vendor_id', $vendor->id)
			->get()
			->groupBy('product_id');

		foreach ($pageItems as $p) {
			$p->setRelation('resellerProducts', $resellerProductsForVendor->get($p->id, collect()));

			$pricing = $chainService->resolveImmediateParentResellPricing($p, $vendor);
			$p->resell_base_price = ($pricing['ok'] ?? false)
				? (float) $pricing['base_price']
				: (float) ($p->min_base_price ?? $p->price);
		}

		$products = new \Illuminate\Pagination\LengthAwarePaginator(
			$pageItems,
			$allProducts->count(),
			$perPage,
			$page,
			[
				'path' => request()->url(),
				'query' => request()->query(),
			]
		);

		// Get network services for logos
		$networkServices = \App\Models\NetworkService::active()
			->whereNotNull('image_path')
			->get()
			->keyBy(function ($service) {
				return strtolower($service->name);
			});

		return view('vendor.marketplace.index', compact('vendor', 'products', 'affiliateParent', 'networkServices'));
	}

	// Add product to resell
	public function addResellerProduct(Request $request)
	{
		$vendor = $this->resolveVendor();
		$chainService = new AffiliateChainService();

		$request->validate([
			'product_id' => 'required|exists:products,id',
			'markup_price' => 'required|numeric|min:0',
		]);

		$product = Product::findOrFail($request->product_id);

		if (!$vendor->affiliate_vendor_id) {
			return back()->with('error', 'You must have an affiliate parent to resell products.');
		}

		$pricing = $chainService->resolveImmediateParentResellPricing($product, $vendor);
		if (!($pricing['ok'] ?? false)) {
			return back()->with('error', 'You can only resell products offered by your affiliate parent.');
		}

		// Check if already reselling
		$existing = ResellerProduct::where('product_id', $product->id)
			->where('reseller_vendor_id', $vendor->id)
			->first();

		if ($existing) {
			return back()->with('error', 'You are already reselling this product.');
		}

		$ownerVendorId = (int) $pricing['owner_vendor_id'];
		$basePrice = (float) $pricing['base_price'];
		$sourceResellerProductId = $pricing['source_reseller_product_id'] ?? null;

		ResellerProduct::create([
			'product_id' => $product->id,
			'source_reseller_product_id' => $sourceResellerProductId,
			'reseller_vendor_id' => $vendor->id,
			'owner_vendor_id' => $ownerVendorId,
			'base_price' => $basePrice,
			'markup_price' => $request->markup_price,
		]);

		return back()->with('success', 'Product added to your reseller catalog!');
	}

	// My reseller products
	public function myResellerProducts()
	{
		$vendor = $this->resolveVendor();
		
		$resellerProducts = ResellerProduct::where('reseller_vendor_id', $vendor->id)
			->with(['product', 'ownerVendor'])
			->latest()
			->paginate(20);

		// Get network services for logos
		$networkServices = \App\Models\NetworkService::active()
			->whereNotNull('image_path')
			->get()
			->keyBy(function ($service) {
				return strtolower($service->name);
			});

		return view('vendor.reseller.index', compact('vendor', 'resellerProducts', 'networkServices'));
	}

	// Update reseller product
	public function updateResellerProduct(Request $request, $id)
	{
		$vendor = $this->resolveVendor();

		$request->validate([
			'markup_price' => 'required|numeric|min:0',
			'is_active' => 'boolean',
		]);

		$resellerProduct = ResellerProduct::where('id', $id)
			->where('reseller_vendor_id', $vendor->id)
			->firstOrFail();

		$resellerProduct->update([
			'markup_price' => $request->markup_price,
			'is_active' => $request->has('is_active'),
		]);

		return back()->with('success', 'Reseller product updated successfully!');
	}

	// Remove reseller product
	public function removeResellerProduct($id)
	{
		$vendor = $this->resolveVendor();

		ResellerProduct::where('id', $id)
			->where('reseller_vendor_id', $vendor->id)
			->delete();

		return back()->with('success', 'Product removed from your reseller catalog.');
	}

	private function calculateWithdrawableBalance(int $vendorId, ?float $totalEarnings = null): float
	{
		$vendor = Vendor::findOrFail($vendorId);
		return (float) $vendor->wallet_balance;
	}

	// ==================== NOTIFICATIONS ====================

	public function notifications()
	{
		$vendor = $this->resolveVendor();
		$notifications = VendorNotification::where('vendor_id', $vendor->id)
			->latest()
			->take(20)
			->get();
		
		$unreadCount = VendorNotification::where('vendor_id', $vendor->id)
			->whereNull('read_at')
			->count();

		return response()->json([
			'notifications' => $notifications,
			'unreadCount' => $unreadCount
		]);
	}

	public function markNotificationRead(VendorNotification $notification)
	{
		$vendor = $this->resolveVendor();
		
		if ($notification->vendor_id !== $vendor->id) {
			abort(403);
		}

		$notification->markAsRead();

		return response()->json(['success' => true]);
	}

	public function markAllNotificationsRead()
	{
		$vendor = $this->resolveVendor();
		
		VendorNotification::where('vendor_id', $vendor->id)
			->whereNull('read_at')
			->update(['read_at' => now()]);

		return response()->json(['success' => true]);
	}

	public function unreadNotificationsCount()
	{
		$vendor = $this->resolveVendor();
		$count = VendorNotification::where('vendor_id', $vendor->id)
			->unread()
			->count();

		return response()->json(['count' => $count]);
	}

	/**
	 * Show vendor settings page
	 */
	public function settings()
	{
		$vendor = $this->resolveVendor();

		return view('vendor.settings.index', [
			'vendor' => $vendor,
		]);
	}

	/**
	 * Update vendor settings
	 */
	public function updateSettings(Request $request)
	{
		$vendor = $this->resolveVendor();

		$providers = array_keys((array) config('momo.providers', []));

		$validated = $request->validate([
			'name' => ['required', 'string', 'max:255'],
			'email' => ['required', 'string', 'email', 'max:255', Rule::unique('vendors', 'email')->ignore($vendor->id)],
			'phone_number' => ['required', 'string', 'max:20'],
			'momo_number' => ['nullable', 'string', 'max:20'],
			'momo_provider' => ['nullable', 'string', Rule::in($providers)],
		]);

		$validated['email'] = strtolower(trim((string) $validated['email']));

		$vendor->update($validated);

		return back()->with('success', 'Settings updated successfully.');
	}

	/**
	 * Update vendor password
	 */
	public function updatePassword(Request $request)
	{
		$vendor = $this->resolveVendor();

		$validated = $request->validate([
			'current_password' => ['required', 'string'],
			'password' => ['required', 'string', 'min:8', 'confirmed'],
		]);

		if (!password_verify($validated['current_password'], $vendor->password)) {
			return back()->withErrors(['current_password' => 'Current password is incorrect.']);
		}

		$vendor->update([
			'password' => bcrypt($validated['password']),
		]);

		return back()->with('success', 'Password updated successfully.');
	}
}
