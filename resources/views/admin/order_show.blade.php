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

        <div class="bg-white shadow-sm rounded-lg p-6">
            <h3 class="text-lg font-semibold">Allocated Pins</h3>
            @if($order->pins && $order->pins->count() > 0)
                <x-table :headers="['PIN', 'Serial', 'Sold At']">
                    @foreach ($order->pins as $pin)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $pin->pin }}</td>
                            <td class="px-6 py-4 text-sm text-gray-900">{{ $pin->serial }}</td>
                            <td class="px-6 py-4 text-sm text-gray-500">{{ $pin->sold_at?->format('Y-m-d H:i:s') }}</td>
                        </tr>
                    @endforeach
                </x-table>
            @else
                <p class="text-sm text-gray-500">No pins allocated to this order.</p>
            @endif
        </div>
    </div>
</x-admin-layout>
@endsection
