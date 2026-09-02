@extends('layouts.vendor')

@section('title', 'Result Checker Orders - XTRA4U')

@section('content')
<x-vendor-layout :vendor="$vendor" title="Result Checker Orders" subtitle="Track your result checker sales" active="result-checkers">
    <div class="space-y-6">
        <div class="bg-white rounded-xl border border-gray-200 p-6">
            <form method="GET" action="{{ route('vendor.result-checkers.orders.index') }}" class="grid gap-4 sm:grid-cols-3 items-end">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Status</label>
                    <select name="status" class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-brand-violet focus:ring-brand-violet">
                        <option value="">All</option>
                        @foreach(['pending_payment','processing','completed','pending_stock','failed'] as $status)
                            <option value="{{ $status }}" @selected($selectedStatus === $status)>{{ str_replace('_', ' ', ucfirst($status)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700">Search</label>
                    <input name="q" value="{{ $searchTerm }}" class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-brand-violet focus:ring-brand-violet" placeholder="Order ID, phone, reference">
                </div>
                <div class="sm:col-span-3 flex gap-2">
                    <x-button type="submit" variant="primary">
                        Apply Filters
                    </x-button>
                    <x-button href="{{ route('vendor.result-checkers.orders.index') }}" variant="secondary">
                        Reset
                    </x-button>
                </div>
            </form>
        </div>

        @php
            $statusVariant = fn ($status) => match ($status) {
                'completed' => 'completed',
                'failed' => 'failed',
                'pending_stock', 'pending_payment' => 'pending',
                'processing' => 'processing',
                default => 'default',
            };
        @endphp

        <x-table :headers="['Order', 'Customer', 'Service', 'Qty', 'Profit', 'Status', 'Date']">
            @forelse ($orders as $order)
                @php
                    $base = (float) ($order->service?->base_price ?? 0);
                    $unit = (float) $order->unit_price;
                    $profit = $order->status === 'completed'
                        ? max(($unit - $base) * $order->quantity, 0)
                        : 0;
                @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 text-sm text-gray-900 font-medium">#{{ $order->id }}</td>
                    <td class="px-6 py-4 text-sm text-gray-900">{{ $order->customer_phone }}</td>
                    <td class="px-6 py-4 text-sm text-gray-700">{{ $order->service?->name ?? 'N/A' }}</td>
                    <td class="px-6 py-4 text-sm text-gray-900">{{ $order->quantity }}</td>
                    <td class="px-6 py-4 text-sm text-gray-900">GHS {{ number_format($profit, 2) }}</td>
                    <td class="px-6 py-4 text-sm">
                        <x-badge :variant="$statusVariant($order->status)">
                            {{ str_replace('_', ' ', $order->status) }}
                        </x-badge>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ $order->created_at?->format('Y-m-d') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-6 py-4">
                        <div class="text-center text-sm text-gray-500 sticky left-0 w-screen max-w-[calc(100vw-3rem)]">No orders found.</div>
                    </td>
                </tr>
            @endforelse
        </x-table>

        @if($orders->hasPages())
            <div class="flex justify-end pt-2">
                {{ $orders->links() }}
            </div>
        @endif
    </div>
</x-vendor-layout>
@endsection
