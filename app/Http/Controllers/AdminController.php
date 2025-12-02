<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\VendorWithdrawal;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function dashboard()
    {
        $activeVendors = Vendor::where('is_approved', true)->count();
        $pendingVendors = Vendor::where('is_approved', false)->latest()->take(5)->get();
        $totalRevenue = Transaction::sum('amount');
        $transactionsToday = Transaction::whereDate('created_at', now()->toDateString())->count();
        $ordersToday = Order::whereDate('created_at', now()->toDateString())->count();

        $pendingWithdrawals = VendorWithdrawal::where('status', VendorWithdrawal::STATUS_PENDING)->count();

        return view('admin.dashboard', [
            'activeVendors' => $activeVendors,
            'pendingVendors' => $pendingVendors,
            'totalRevenue' => $totalRevenue,
            'transactionsToday' => $transactionsToday,
            'ordersToday' => $ordersToday,
            'pendingWithdrawals' => $pendingWithdrawals,
        ]);
    }

    // Vendor request approvals
    public function vendorRequests()
    {
        $pendingVendors = Vendor::where('is_approved', false)->get();
        return view('admin.vendor_requests', compact('pendingVendors'));
    }

    public function approveVendor($id)
    {
        $vendor = Vendor::findOrFail($id);
        $vendor->is_approved = true;
        $vendor->save();
        return redirect()->back()->with('success', 'Vendor approved.');
    }

    // View vendors
    public function vendors()
    {
        $vendors = Vendor::all()->filter(function($vendor) {
            return Gate::allows('view', $vendor);
        });
        return view('admin.vendors', compact('vendors'));
    }

    // Suspend vendor
    public function suspendVendor($id)
    {
        $vendor = Vendor::findOrFail($id);
        if (Gate::denies('suspend', $vendor)) {
            abort(403, 'Unauthorized');
        }
        $vendor->is_approved = false;
        $vendor->save();
        return redirect()->back()->with('success', 'Vendor suspended.');
    }

    // View transactions
    public function transactions()
    {
        $transactions = Transaction::with('order')->get();
        return view('admin.transactions', compact('transactions'));
    }

    // View commissions
    public function commissions()
    {
        $commissions = Transaction::sum('commission_deducted');
        return view('admin.commissions', compact('commissions'));
    }

    // View all orders
    public function orders()
    {
        $orders = Order::with('vendor')->get();
        return view('admin.orders', compact('orders'));
    }
}
