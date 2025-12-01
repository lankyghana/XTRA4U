<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\Vendor;
use App\Services\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VendorDashboardController extends Controller
{
	protected $transactionService;

	public function __construct(TransactionService $transactionService)
	{
		$this->transactionService = $transactionService;
	}

	public function index()
	{
		$vendorGuardUser = Auth::guard('vendor')->user();
		$user = Auth::user();
		$vendor = $vendorGuardUser instanceof Vendor
			? $vendorGuardUser
			: ($user instanceof Vendor ? $user : ($user->vendor ?? null));

		abort_unless($vendor, 403, 'Vendor account required.');

		$vendorId = $vendor->id;
		$transactions = $this->transactionService->getVendorTransactions($vendorId);
		$commissions = $this->transactionService->getVendorCommissions($vendorId);
		$totalEarnings = $this->transactionService->getVendorEarnings($vendorId);
		$totalSales = $transactions->sum('amount');
		$orders = Order::where('vendor_id', $vendorId)->latest()->get();
		$products = Product::where('vendor_id', $vendorId)->orderBy('name')->get();
		$storeLink = route('storefront.vendor', ['vendor' => $vendor]);

		return view('vendor_dashboard', compact(
			'vendor',
			'orders',
			'products',
			'totalSales',
			'totalEarnings',
			'storeLink',
			'transactions',
			'commissions'
		));
	}
}
