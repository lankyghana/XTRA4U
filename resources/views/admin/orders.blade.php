@extends('layouts.admin')

@section('content')
<x-admin-layout title="Orders" subtitle="Track every purchase flowing through the platform" active="orders">
    <div class="space-y-6">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">All Orders</h2>
            <p class="text-sm text-gray-500">Monitor order amounts, statuses, and fulfillment dates.</p>
        </div>

        <x-table :headers="['Order ID', 'Vendor', 'Service', 'Amount Paid', 'Status', 'Date']">
            @forelse ($orders as $order)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 text-sm font-medium text-gray-900">#{{ $order->id }}</td>
                    <td class="px-6 py-4 text-sm text-gray-900">{{ $order->vendor->name ?? 'N/A' }}</td>
                    <td class="px-6 py-4 text-sm text-gray-700">{{ $order->display_service_name }}</td>
                    <td class="px-6 py-4 text-sm font-semibold text-gray-900">₵{{ number_format($order->amount_paid, 2) }}</td>
                    <td class="px-6 py-4 text-sm text-gray-900">
                        {{ $order->status ?? 'N/A' }}
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ $order->created_at?->format('Y-m-d') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500">No orders found.</td>
                </tr>
            @endforelse
        </x-table>

        @php($canPaginate = is_object($orders) && method_exists($orders, 'hasPages'))
        @if ($canPaginate && $orders->hasPages())
            <div class="flex justify-end pt-2">
                {{ $orders->links() }}
            </div>
        @endif
    </div>
</x-admin-layout>
@endsection
