@extends('layouts.admin')

@section('content')
<x-admin-layout title="Orders" subtitle="Track every purchase flowing through the platform" active="orders">
    <div class="space-y-6">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">All Orders</h2>
            <p class="text-sm text-gray-500">Monitor order amounts, statuses, and fulfillment dates.</p>
        </div>

        <form method="GET" action="{{ route('admin.orders.index') }}" class="flex flex-col sm:flex-row gap-3 sm:items-center">
            <div class="flex-1">
                <input
                    type="text"
                    name="q"
                    value="{{ $search ?? request('q') }}"
                    placeholder="Search by order id, reference, phone, or vendor"
                    class="w-full text-sm border-gray-300 rounded-lg focus:ring-brand-bright-blue focus:border-brand-bright-blue px-3 py-2 font-medium shadow-sm"
                />
            </div>
            <div class="flex gap-2">
                <button type="submit" class="text-sm px-4 py-2 rounded-lg bg-brand-bright-blue text-white font-medium shadow-sm hover:bg-brand-deep-blue">
                    Search
                </button>
                @if (($search ?? request('q')))
                    <a href="{{ route('admin.orders.index') }}" class="text-sm px-4 py-2 rounded-lg border border-gray-300 text-gray-700 font-medium shadow-sm hover:bg-gray-50">
                        Clear
                    </a>
                @endif
            </div>
        </form>

        <x-table :headers="['Order ID', 'Vendor', 'Service', 'Amount Paid', 'Status', 'Date', '']">
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
                    <td class="px-6 py-4 text-sm text-gray-500">
                        <a href="{{ route('admin.orders.show', $order) }}" class="text-indigo-600 hover:text-indigo-900">View</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-6 py-4 text-center text-sm text-gray-500">No orders found.</td>
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
