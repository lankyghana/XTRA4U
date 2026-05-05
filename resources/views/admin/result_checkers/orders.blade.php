@extends('layouts.admin')

@section('content')
<x-admin-layout title="Result Checker Orders" subtitle="Monitor and recover result checker orders" active="result-checkers">
    <div class="space-y-6">
        @if(session('success'))
            <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg">
                {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg">
                {{ session('error') }}
            </div>
        @endif

        <div class="bg-white shadow-sm rounded-lg p-6">
            <form method="GET" action="{{ route('admin.result-checkers.orders.index') }}" class="grid gap-4 sm:grid-cols-4 items-end">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Service</label>
                    <select name="service_id" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                        <option value="">All</option>
                        @foreach($services as $service)
                            <option value="{{ $service->id }}" @selected($selectedService == $service->id)>{{ $service->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Status</label>
                    <select name="status" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                        <option value="">All</option>
                        @foreach(['pending_payment','processing','completed','pending_stock','failed'] as $status)
                            <option value="{{ $status }}" @selected($selectedStatus === $status)>{{ str_replace('_', ' ', ucfirst($status)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700">Search</label>
                    <input name="q" value="{{ $searchTerm }}" class="mt-1 w-full rounded-md border-gray-300 shadow-sm" placeholder="Order ID, phone, reference">
                </div>
                <div class="sm:col-span-4 flex gap-2">
                    <button type="submit" class="inline-flex items-center px-4 py-2 bg-gray-900 text-white rounded-md text-sm font-semibold">
                        Apply Filters
                    </button>
                    <a href="{{ route('admin.result-checkers.orders.index') }}" class="inline-flex items-center px-4 py-2 bg-gray-100 text-gray-700 rounded-md text-sm font-semibold">
                        Reset
                    </a>
                </div>
            </form>
        </div>

        @php
            $mask = fn ($value) => strlen((string) $value) <= 4
                ? str_repeat('*', strlen((string) $value))
                : str_repeat('*', max(strlen((string) $value) - 4, 0)) . substr((string) $value, -4);
            $statusVariant = fn ($status) => match ($status) {
                'completed' => 'completed',
                'failed' => 'failed',
                'pending_stock', 'pending_payment' => 'pending',
                'processing' => 'processing',
                default => 'default',
            };
        @endphp

        <x-table :headers="['Order', 'Customer', 'Service', 'Qty', 'Status', 'Pins', 'Actions']">
            @forelse ($orders as $order)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 text-sm text-gray-900 font-medium">#{{ $order->id }}</td>
                    <td class="px-6 py-4 text-sm text-gray-900">
                        <div>{{ $order->customer_phone }}</div>
                        <div class="text-xs text-gray-500">{{ $order->vendor?->name ?? 'N/A' }}</div>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-700">{{ $order->service?->name ?? 'N/A' }}</td>
                    <td class="px-6 py-4 text-sm text-gray-900">{{ $order->quantity }}</td>
                    <td class="px-6 py-4 text-sm">
                        <x-badge :variant="$statusVariant($order->status)">
                            {{ str_replace('_', ' ', $order->status) }}
                        </x-badge>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-700">
                        @if($order->pins->count())
                            {{ $order->pins->count() }} • {{ $mask($order->pins->first()->serial) }}
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-900 space-x-2">
                        <a href="{{ route('admin.result-checkers.orders.show', $order) }}" class="text-brand-deep-blue font-semibold">View</a>
                        <form action="{{ route('admin.result-checkers.orders.retry', $order) }}" method="POST" class="inline">
                            @csrf
                            <button type="submit" class="text-blue-600 font-semibold">Retry</button>
                        </form>
                        <form action="{{ route('admin.result-checkers.orders.mark-failed', $order) }}" method="POST" class="inline">
                            @csrf
                            <button type="submit" class="text-red-600 font-semibold" @disabled($order->status === 'completed')>Fail</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-6 py-4 text-center text-sm text-gray-500">No orders found.</td>
                </tr>
            @endforelse
        </x-table>

        @if($orders->hasPages())
            <div class="flex justify-end pt-2">
                {{ $orders->links() }}
            </div>
        @endif
    </div>
</x-admin-layout>
@endsection
