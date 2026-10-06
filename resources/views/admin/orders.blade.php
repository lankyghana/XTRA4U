@extends('layouts.admin')

@section('content')
<x-admin-layout title="Orders" subtitle="Track every purchase flowing through the platform." active="orders">
    <div class="space-y-4">
        <x-admin.filters :action="route('admin.orders.index')" :search="$search ?? request('q', '')" placeholder="Search by order id, reference, phone, or vendor" />

        <x-admin.table :headers="['Order ID', 'Vendor', 'Service', 'Amount Paid', 'Status', 'Date', '']">
            @forelse ($orders as $order)
                <tr>
                    <td class="font-medium text-gray-900">#{{ $order->id }}</td>
                    <td class="text-gray-900">{{ $order->vendor->name ?? 'N/A' }}</td>
                    <td class="text-gray-700">{{ $order->display_service_name }}</td>
                    <td class="whitespace-nowrap font-semibold text-gray-900">₵{{ number_format($order->amount_paid, 2) }}</td>
                    <td><x-admin.status :status="$order->status ?? ''" /></td>
                    <td class="whitespace-nowrap text-gray-500">{{ $order->created_at?->format('Y-m-d') }}</td>
                    <td class="text-right"><a href="{{ route('admin.orders.show', $order) }}" class="admin-link">View</a></td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <x-admin.empty title="No orders found" :description="filled($search ?? request('q')) ? 'Try a different search term.' : 'Orders will appear here as customers purchase.'" />
                    </td>
                </tr>
            @endforelse
        </x-admin.table>

        @php($canPaginate = is_object($orders) && method_exists($orders, 'hasPages'))
        @if ($canPaginate && $orders->hasPages())
            <div class="flex justify-end pt-2">
                {{ $orders->links() }}
            </div>
        @endif
    </div>
</x-admin-layout>
@endsection
