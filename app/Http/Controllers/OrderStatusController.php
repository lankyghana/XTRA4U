<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;

class OrderStatusController extends Controller
{
    /**
     * Show the order status check page
     */
    public function index()
    {
        return view('order-status.index');
    }

    /**
     * Check order status by recipient phone number
     */
    public function check(Request $request)
    {
        $request->validate([
            'phone' => ['required', 'string', 'min:10', 'max:15'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $phone = $this->formatPhoneNumber($request->phone);
        $limit = $request->input('limit', 20);

        // Find orders by recipient phone number
        $orders = Order::where('recipient_phone_number', $phone)
            ->orWhere('recipient_phone_number', $request->phone)
            ->orWhere('recipient_phone_number', 'LIKE', '%' . substr($phone, -9))
            ->with(['vendor:id,name,vendor_code', 'service:id,name'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        if ($orders->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No orders found for this phone number.',
                'orders' => []
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Orders found!',
            'orders' => $orders->map(function ($order) {
                return [
                    'id' => $order->id,
                    'reference' => $order->payment_reference ?? 'ORD-' . str_pad($order->id, 6, '0', STR_PAD_LEFT),
                    'service' => $order->service_purchased ?? ($order->service->name ?? 'N/A'),
                    'amount' => number_format($order->amount_paid, 2),
                    'status' => $order->status,
                    'status_label' => $this->getStatusLabel($order->status),
                    'status_color' => $this->getStatusColor($order->status),
                    'payment_status' => $order->payment_status,
                    'vendor_name' => $order->vendor->name ?? 'N/A',
                    'vendor_code' => $order->vendor->vendor_code ?? 'N/A',
                    'date' => $order->created_at->format('M d, Y'),
                    'time' => $order->created_at->format('h:i A'),
                    'updated_at' => $order->updated_at->diffForHumans(),
                ];
            })
        ]);
    }

    /**
     * API endpoint for real-time status polling
     */
    public function poll(Request $request)
    {
        $request->validate([
            'order_ids' => ['required', 'array'],
            'order_ids.*' => ['integer'],
        ]);

        $orders = Order::whereIn('id', $request->order_ids)
            ->get(['id', 'status', 'updated_at']);

        return response()->json([
            'orders' => $orders->map(function ($order) {
                return [
                    'id' => $order->id,
                    'status' => $order->status,
                    'status_label' => $this->getStatusLabel($order->status),
                    'status_color' => $this->getStatusColor($order->status),
                    'updated_at' => $order->updated_at->diffForHumans(),
                ];
            })
        ]);
    }

    /**
     * Format phone number to standard format
     */
    protected function formatPhoneNumber(string $phone): string
    {
        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // If starts with 233, keep it
        if (str_starts_with($phone, '233')) {
            return $phone;
        }

        // If starts with 0, replace with 233
        if (str_starts_with($phone, '0')) {
            return '233' . substr($phone, 1);
        }

        // Otherwise, prepend 233
        return '233' . $phone;
    }

    /**
     * Get human-readable status label
     */
    protected function getStatusLabel(string $status): string
    {
        return match (strtolower($status)) {
            'pending' => 'Pending',
            'processing' => 'Processing',
            'completed' => 'Completed',
            'cancelled', 'canceled' => 'Cancelled',
            'failed' => 'Failed',
            'refunded' => 'Refunded',
            default => ucfirst($status),
        };
    }

    /**
     * Get status color classes for UI
     */
    protected function getStatusColor(string $status): array
    {
        return match (strtolower($status)) {
            'pending' => ['bg' => 'bg-yellow-100', 'text' => 'text-yellow-800', 'dot' => 'bg-yellow-500'],
            'processing' => ['bg' => 'bg-blue-100', 'text' => 'text-blue-800', 'dot' => 'bg-blue-500'],
            'completed' => ['bg' => 'bg-green-100', 'text' => 'text-green-800', 'dot' => 'bg-green-500'],
            'cancelled', 'canceled' => ['bg' => 'bg-red-100', 'text' => 'text-red-800', 'dot' => 'bg-red-500'],
            'failed' => ['bg' => 'bg-red-100', 'text' => 'text-red-800', 'dot' => 'bg-red-500'],
            'refunded' => ['bg' => 'bg-purple-100', 'text' => 'text-purple-800', 'dot' => 'bg-purple-500'],
            default => ['bg' => 'bg-gray-100', 'text' => 'text-gray-800', 'dot' => 'bg-gray-500'],
        };
    }
}
