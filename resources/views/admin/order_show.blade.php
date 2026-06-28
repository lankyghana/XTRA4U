@extends('layouts.admin')

@section('content')
<x-admin-layout title="Order #{{ $order->id }}" subtitle="Order details and fulfillment status" active="orders">
    <div class="space-y-6">
        <div class="bg-white shadow-sm rounded-lg p-6">
            <h3 class="text-lg font-semibold">Order Details</h3>
            <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <p class="text-sm text-gray-600">Order ID</p>
                    <p class="font-semibold">{{ $order->id }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-600">Vendor</p>
                    <p class="font-semibold">{{ $order->vendor->name ?? 'N/A' }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-600">Service</p>
                    <p class="font-semibold">{{ $order->display_service_name }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-600">Amount Paid</p>
                    <p class="font-semibold">₵{{ number_format($order->amount_paid, 2) }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-600">Status</p>
                    <p class="font-semibold">{{ $order->status ?? 'N/A' }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-600">Date</p>
                    <p class="font-semibold">{{ $order->created_at?->format('Y-m-d H:i:s') }}</p>
                </div>
            </div>
        </div>

        {{-- Allocated Pins only apply to Result Checker orders, not regular bundle orders --}}
    </div>
</x-admin-layout>
@endsection
