<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\VendorWithdrawal;
use App\Models\AdminNotification;
use App\Models\ResultCheckerOrder;
use App\Services\WalletService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AdminController extends Controller
{
    public function dashboard(WalletService $walletService)
    {
        $activeVendors = Vendor::where('is_approved', true)->count();
        $pendingVendors = Vendor::where('is_approved', false)->latest()->take(5)->get();
        // Platform revenue = total commissions from completed transactions
        $totalRevenue = Transaction::whereIn('payment_status', ['completed', 'successful'])->sum('commission_amount');
        $transactionsToday = Transaction::whereDate('created_at', now()->toDateString())->count();
        $ordersToday = Order::whereDate('created_at', now()->toDateString())->count();
        $totalWithdrawableBalances = $walletService->getTotalWithdrawableBalance();

        $pendingWithdrawals = VendorWithdrawal::where('status', VendorWithdrawal::STATUS_PROCESSING)->count();

        $manualQueueLastRequestedAt = Cache::get('queue_manual_run_requested_at');
        $manualQueueLastFinishedAt = Cache::get('queue_manual_run_last_finished_at');

        // Results Checker Stats
        $resultCheckerOrdersToday = ResultCheckerOrder::whereDate('created_at', now()->toDateString())->count();
        $resultCheckerRevenueToday = ResultCheckerOrder::whereDate('created_at', now()->toDateString())
            ->whereIn('status', ['completed', 'pending_payment'])
            ->sum('total_price');
        $resultCheckerCompletedToday = ResultCheckerOrder::whereDate('created_at', now()->toDateString())
            ->where('status', 'completed')
            ->count();
        $resultCheckerPendingStock = ResultCheckerOrder::where('status', 'pending_stock')->count();

        return view('admin.dashboard', [
            'activeVendors' => $activeVendors,
            'pendingVendors' => $pendingVendors,
            'totalRevenue' => $totalRevenue,
            'transactionsToday' => $transactionsToday,
            'ordersToday' => $ordersToday,
            'totalWithdrawableBalances' => $totalWithdrawableBalances,
            'pendingWithdrawals' => $pendingWithdrawals,
            'manualQueueLastRequestedAt' => $manualQueueLastRequestedAt,
            'manualQueueLastFinishedAt' => $manualQueueLastFinishedAt,
            'resultCheckerOrdersToday' => $resultCheckerOrdersToday,
            'resultCheckerRevenueToday' => $resultCheckerRevenueToday,
            'resultCheckerCompletedToday' => $resultCheckerCompletedToday,
            'resultCheckerPendingStock' => $resultCheckerPendingStock,
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
        $commissions = Transaction::whereIn('payment_status', ['completed', 'successful'])->sum('commission_amount');
        return view('admin.commissions', compact('commissions'));
    }

    // View all orders
    public function orders()
    {
		$orders = Order::with(['vendor', 'service'])->get();
        return view('admin.orders', compact('orders'));
    }

    // ==================== NOTIFICATIONS ====================

    public function notifications()
    {
        $notifications = AdminNotification::latest()->take(30)->get();
        $unreadCount = AdminNotification::unread()->count();

        return response()->json([
            'notifications' => $notifications,
            'unreadCount' => $unreadCount
        ]);
    }

    public function markNotificationRead(AdminNotification $notification)
    {
        $notification->markAsRead();
        return response()->json(['success' => true]);
    }

    public function markAllNotificationsRead()
    {
        AdminNotification::whereNull('read_at')->update(['read_at' => now()]);
        return response()->json(['success' => true]);
    }
}
