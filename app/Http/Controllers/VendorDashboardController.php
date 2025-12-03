<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\Vendor;
use App\Models\VendorWithdrawal;
use App\Services\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
		$totalSales = $transactions->sum('amount');
		$totalEarnings = $transactions->sum('vendor_earning');
		$commissions = $transactions->sum('commission_amount');
		$orders = Order::where('vendor_id', $vendorId)->latest()->get();
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
			->paginate(15);

		return view('vendor.orders.index', compact('vendor', 'orders'));
	}

	public function requestWithdrawal(Request $request)
	{
		$vendor = $this->resolveVendor();
		$vendorId = $vendor->id;
		$totalEarnings = $this->transactionService->getVendorEarnings($vendorId);
		$withdrawableBalance = $this->calculateWithdrawableBalance($vendorId, $totalEarnings);

		$data = $request->validate([
			'withdraw_amount' => ['required', 'numeric', 'min:1'],
			'notes' => ['nullable', 'string', 'max:255'],
		]);

		if ($data['withdraw_amount'] > $withdrawableBalance) {
			return back()->withErrors([
				'withdraw_amount' => 'Requested amount exceeds your withdrawable balance.',
			])->withInput();
		}

		VendorWithdrawal::create([
			'vendor_id' => $vendorId,
			'amount' => $data['withdraw_amount'],
			'status' => 'pending',
			'reference' => Str::upper(Str::random(10)),
			'notes' => $data['notes'] ?? null,
		]);

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
}
