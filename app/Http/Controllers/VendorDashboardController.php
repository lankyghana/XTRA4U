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
use App\Services\MomoPayoutService;
use App\Services\SmsService;
use App\Services\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
		$transactions = $this->transactionService->getVendorTransactions($vendorId);
		
		// Only count completed transactions for sales/earnings
		$completedTransactions = $transactions->where('payment_status', 'completed');
		$totalSales = $completedTransactions->sum('amount');
		$totalEarnings = $completedTransactions->sum('vendor_earning');
		$commissions = $completedTransactions->sum('commission_amount');
		
		// Get orders (both direct and as owner of affiliate orders)
		$orders = Order::where('vendor_id', $vendorId)
			->orWhere('owner_vendor_id', $vendorId)
			->latest()
			->get();
		$products = Product::where('vendor_id', $vendorId)->orderBy('name')->get();
		$storeLink = route('storefront.vendor', ['vendor' => $vendor]);
		$recentWithdrawals = VendorWithdrawal::where('vendor_id', $vendorId)->latest()->take(5)->get();
		$withdrawableBalance = $this->calculateWithdrawableBalance($vendorId, $totalEarnings);

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
			'withdrawableBalance'
		));
	}

	public function orders()
	{
		$vendor = $this->resolveVendor();
		$orders = Order::where('vendor_id', $vendor->id)
			->latest()
			->paginate(20);

		return view('vendor.orders.index', compact('vendor', 'orders'));
	}

	/**
	 * Show affiliate orders - orders where this vendor is the product owner
	 * but the product was sold by a reseller
	 */
	public function affiliateOrders()
	{
		$vendor = $this->resolveVendor();
		
		// Get orders where this vendor is the owner (their products sold by resellers)
		$orders = Order::where('owner_vendor_id', $vendor->id)
			->where('is_reseller_order', true)
			->with('resellerVendor')
			->latest()
			->paginate(20);

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

		// Get all orders where this vendor is involved (as primary vendor OR as product owner in affiliate orders)
		$allVendorOrders = Order::where('vendor_id', $vendorId)
			->orWhere('owner_vendor_id', $vendorId)
			->get();

		// Total orders for this vendor
		$totalOrders = $allVendorOrders->count();
		$ordersThisMonth = $allVendorOrders->filter(function ($order) {
			return $order->created_at->month === now()->month && $order->created_at->year === now()->year;
		})->count();

		// Use Transactions for accurate earnings (this correctly tracks both owner and reseller earnings)
		$transactions = Transaction::where('vendor_id', $vendorId)->get();
		$completedTransactions = $transactions->filter(function ($t) {
			return $t->payment_status === 'completed';
		});

		// Average earning per transaction (more accurate than order amount for vendors with mixed roles)
		$averageOrderValue = $completedTransactions->avg('vendor_earning') ?? 0;

		// Completion rate based on orders this vendor is responsible for
		// For regular orders: vendor_id = this vendor
		// For affiliate orders where vendor is owner: owner_vendor_id = this vendor
		$ownOrders = Order::where('vendor_id', $vendorId)->where('is_reseller_order', false)->get();
		$affiliateOrdersAsOwner = Order::where('owner_vendor_id', $vendorId)->where('is_reseller_order', true)->get();
		$responsibleOrders = $ownOrders->concat($affiliateOrdersAsOwner);
		$totalResponsibleOrders = $responsibleOrders->count();
		$completedResponsibleOrders = $responsibleOrders->where('status', 'Completed')->count();
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

		$topProducts = collect($ownProductSales->get())
			->concat($affiliateProductSales->get())
			->concat($resellerProductSales->get())
			->groupBy('name')
			->map(function ($items, $name) {
				return (object) [
					'name' => $name,
					'orders_count' => $items->sum('orders_count'),
					'total_sales' => $items->sum('total_sales'),
				];
			})
			->sortByDesc('orders_count')
			->take(5)
			->values();

		// Status breakdown for orders this vendor is responsible for
		$statusBreakdown = [
			'pending' => $responsibleOrders->where('status', 'Pending')->count(),
			'processing' => $responsibleOrders->where('status', 'Processing')->count(),
			'completed' => $responsibleOrders->where('status', 'Completed')->count(),
			'failed' => $responsibleOrders->where('status', 'Failed')->count(),
			'cancelled' => $responsibleOrders->where('status', 'Cancelled')->count(),
		];

		// Revenue summaries - use transactions for accurate vendor-specific earnings
		$revenueToday = Transaction::where('vendor_id', $vendorId)
			->whereDate('created_at', now())
			->where('payment_status', 'completed')
			->sum('vendor_earning');
		$ordersToday = $allVendorOrders->filter(function ($order) {
			return $order->created_at->isToday();
		})->count();

		$revenueThisWeek = Transaction::where('vendor_id', $vendorId)
			->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])
			->where('payment_status', 'completed')
			->sum('vendor_earning');
		$ordersThisWeek = $allVendorOrders->filter(function ($order) {
			return $order->created_at->between(now()->startOfWeek(), now()->endOfWeek());
		})->count();

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
		// - Regular orders: vendor_id must match
		// - Affiliate orders: owner_vendor_id must match (owner manages fulfillment)
		$isOwnerOfAffiliateOrder = $order->is_reseller_order && $order->owner_vendor_id === $vendor->id;
		$isRegularOrder = !$order->is_reseller_order && $order->vendor_id === $vendor->id;
		
		if (!$isOwnerOfAffiliateOrder && !$isRegularOrder) {
			abort(403, 'Unauthorized action.');
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
		$totalEarnings = $this->transactionService->getVendorEarnings($vendorId);
		$withdrawableBalance = $this->calculateWithdrawableBalance($vendorId, $totalEarnings);

		$data = $request->validate([
			'withdraw_amount' => ['required', 'numeric', 'min:1'],
			'momo_number' => ['required', 'string', 'regex:/^0[235][0-9]{8}$/'],
			'momo_network' => ['required', 'string', 'in:MTN,TELECEL,AirtelTigo'],
			'notes' => ['nullable', 'string', 'max:255'],
		], [
			'momo_number.regex' => 'Please enter a valid Ghana mobile number (e.g., 0241234567).',
			'momo_network.in' => 'Please select a valid network (MTN, TELECEL, or AirtelTigo).',
		]);

		if ($data['withdraw_amount'] > $withdrawableBalance) {
			return back()->withErrors([
				'withdraw_amount' => 'Requested amount exceeds your withdrawable balance.',
			])->withInput();
		}

		$withdrawal = VendorWithdrawal::create([
			'vendor_id' => $vendorId,
			'amount' => $data['withdraw_amount'],
			'momo_number' => $data['momo_number'],
			'momo_network' => $data['momo_network'],
			'status' => 'pending',
			'reference' => Str::upper(Str::random(10)),
			'notes' => $data['notes'] ?? null,
		]);

		// Notify admin about new withdrawal request
		AdminNotification::notifyWithdrawalRequest($withdrawal);

		return back()->with('status', 'Withdrawal request submitted. Our team will review it shortly.');
	}



	public function withdrawals()
	{
		$vendor = $this->resolveVendor();
		$vendorId = $vendor->id;
		$totalEarnings = $this->transactionService->getVendorEarnings($vendorId);
		$withdrawableBalance = $this->calculateWithdrawableBalance($vendorId, $totalEarnings);
		$withdrawals = VendorWithdrawal::where('vendor_id', $vendorId)
			->latest()
			->paginate(10);
		$pendingTotal = VendorWithdrawal::where('vendor_id', $vendorId)
			->where('status', VendorWithdrawal::STATUS_PENDING)
			->sum('amount');
		$approvedTotal = VendorWithdrawal::where('vendor_id', $vendorId)
			->where('status', VendorWithdrawal::STATUS_APPROVED)
			->sum('amount');

		return view('vendor.withdrawals.index', compact(
			'vendor',
			'withdrawals',
			'withdrawableBalance',
			'pendingTotal',
			'approvedTotal'
		));
	}

	private function resolveVendor(): Vendor
	{
		$vendorGuardUser = Auth::guard('vendor')->user();
		$user = Auth::user();
		$vendor = $vendorGuardUser instanceof Vendor
			? $vendorGuardUser
			: ($user instanceof Vendor ? $user : ($user->vendor ?? null));

		abort_unless($vendor, 403, 'Vendor account required.');

		return $vendor;
	}

	// Marketplace - Browse products from affiliate parent to resell
	public function marketplace()
	{
		$vendor = $this->resolveVendor();
		
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
		
		// Get all resellable products from affiliate parent
		$products = Product::where('vendor_id', $affiliateParent->id)
			->where('is_active', true)
			->where('is_resellable', true)
			->with(['resellerProducts' => function ($query) use ($vendor) {
				$query->where('reseller_vendor_id', $vendor->id);
			}])
			->paginate(12);

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

		$request->validate([
			'product_id' => 'required|exists:products,id',
			'markup_price' => 'required|numeric|min:0',
		]);

		$product = Product::findOrFail($request->product_id);

		// Verify product belongs to affiliate parent
		if ($product->vendor_id !== $vendor->affiliate_vendor_id) {
			return back()->with('error', 'You can only resell products from your affiliate parent.');
		}

		// Check if already reselling
		$existing = ResellerProduct::where('product_id', $product->id)
			->where('reseller_vendor_id', $vendor->id)
			->first();

		if ($existing) {
			return back()->with('error', 'You are already reselling this product.');
		}

		ResellerProduct::create([
			'product_id' => $product->id,
			'reseller_vendor_id' => $vendor->id,
			'owner_vendor_id' => $product->vendor_id,
			'base_price' => $product->min_base_price ?? $product->price,
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
		$totalEarnings ??= $this->transactionService->getVendorEarnings($vendorId);
		$lockedAmount = VendorWithdrawal::where('vendor_id', $vendorId)
			->whereIn('status', [
				VendorWithdrawal::STATUS_PENDING,
				VendorWithdrawal::STATUS_APPROVED,
				VendorWithdrawal::STATUS_PROCESSING,
			])
			->sum('amount');

		return max($totalEarnings - $lockedAmount, 0);
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
}
