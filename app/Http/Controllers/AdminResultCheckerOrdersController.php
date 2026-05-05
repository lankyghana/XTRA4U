<?php

namespace App\Http\Controllers;

use App\Jobs\RetryResultCheckerOrder;
use App\Models\NetworkService;
use App\Models\ResultCheckerOrder;
use Illuminate\Http\Request;

class AdminResultCheckerOrdersController extends Controller
{
    public function index(Request $request)
    {
        $services = NetworkService::resultsChecker()
            ->orderBy('name')
            ->get();

        $ordersQuery = ResultCheckerOrder::query()
            ->with(['service', 'vendor', 'pins'])
            ->latest();

        if ($request->filled('service_id')) {
            $ordersQuery->where('service_id', $request->input('service_id'));
        }

        if ($request->filled('status')) {
            $ordersQuery->where('status', $request->input('status'));
        }

        if ($request->filled('q')) {
            $q = trim((string) $request->input('q'));
            $ordersQuery->where(function ($query) use ($q) {
                $query->where('customer_phone', 'like', "%{$q}%")
                    ->orWhere('payment_reference', 'like', "%{$q}%");

                if (is_numeric($q)) {
                    $query->orWhere('id', (int) $q);
                }
            });
        }

        $orders = $ordersQuery->paginate(50)->withQueryString();

        return view('admin.result_checkers.orders', [
            'orders' => $orders,
            'services' => $services,
            'selectedService' => $request->input('service_id'),
            'selectedStatus' => $request->input('status'),
            'searchTerm' => $request->input('q'),
        ]);
    }

    public function show(ResultCheckerOrder $order)
    {
        $order->load(['service', 'vendor', 'pins']);

        return view('admin.result_checkers.order_show', [
            'order' => $order,
        ]);
    }

    public function retry(ResultCheckerOrder $order)
    {
        dispatch(new RetryResultCheckerOrder($order->id));

        return back()->with('success', 'Retry job queued for this order.');
    }

    public function markFailed(ResultCheckerOrder $order)
    {
        if ($order->status === 'completed') {
            return back()->with('error', 'Completed orders cannot be marked as failed.');
        }

        $order->update(['status' => 'failed']);

        return back()->with('success', 'Order marked as failed.');
    }
}
