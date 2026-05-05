@extends('layouts.admin')

@section('content')
<x-admin-layout title="Order #{{ $order->id }}" subtitle="Result checker order details" active="result-checkers">
    <div class="space-y-6">
        <div class="flex flex-wrap gap-3">
            <form action="{{ route('admin.result-checkers.orders.retry', $order) }}" method="POST">
                @csrf
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-md text-sm font-semibold">
                    Retry Fulfillment
                </button>
            </form>
            <form action="{{ route('admin.result-checkers.orders.mark-failed', $order) }}" method="POST">
                @csrf
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-md text-sm font-semibold" @disabled($order->status === 'completed')>
                    Mark Failed
                </button>
            </form>
            <a href="{{ route('admin.result-checkers.orders.index') }}" class="inline-flex items-center px-4 py-2 bg-gray-100 text-gray-700 rounded-md text-sm font-semibold">
                Back to Orders
            </a>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="bg-white shadow-sm rounded-lg p-6 space-y-3">
                <h3 class="text-base font-semibold text-gray-900">Order Info</h3>
                <div class="text-sm text-gray-700 space-y-1">
                    <div><strong>Customer:</strong> {{ $order->customer_phone }} {{ $order->customer_name ? "({$order->customer_name})" : '' }}</div>
                    <div><strong>Vendor:</strong> {{ $order->vendor?->name ?? 'N/A' }}</div>
                    <div><strong>Service:</strong> {{ $order->service?->name ?? 'N/A' }}</div>
                    <div><strong>Quantity:</strong> {{ $order->quantity }}</div>
                    <div><strong>Status:</strong> {{ str_replace('_', ' ', $order->status) }}</div>
                    <div><strong>Paid At:</strong> {{ $order->paid_at?->format('Y-m-d H:i') ?? '—' }}</div>
                    <div><strong>SMS Sent:</strong> {{ $order->sms_sent_at?->format('Y-m-d H:i') ?? '—' }}</div>
                </div>
            </div>

            <div class="bg-white shadow-sm rounded-lg p-6 space-y-3">
                <h3 class="text-base font-semibold text-gray-900">Payment</h3>
                <div class="text-sm text-gray-700 space-y-1">
                    <div><strong>Unit Price:</strong> GHS {{ number_format((float) $order->unit_price, 2) }}</div>
                    <div><strong>Total Price:</strong> GHS {{ number_format((float) $order->total_price, 2) }}</div>
                    <div><strong>Gateway:</strong> {{ $order->payment_gateway ?? '—' }}</div>
                    <div><strong>Reference:</strong> {{ $order->payment_reference ?? '—' }}</div>
                </div>
            </div>
        </div>

        @php
            $mask = fn ($value) => strlen((string) $value) <= 4
                ? str_repeat('*', strlen((string) $value))
                : str_repeat('*', max(strlen((string) $value) - 4, 0)) . substr((string) $value, -4);
        @endphp

        <div class="bg-white shadow-sm rounded-lg p-6 space-y-4">
            <h3 class="text-base font-semibold text-gray-900">Assigned PINs</h3>
            <x-table :headers="['Serial', 'PIN', 'Status']">
                @forelse ($order->pins as $pin)
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 text-sm text-gray-900">{{ $mask($pin->serial) }}</td>
                        <td class="px-6 py-4 text-sm text-gray-900">{{ $mask($pin->pin) }}</td>
                        <td class="px-6 py-4 text-sm text-gray-700">{{ ucfirst($pin->status) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-6 py-4 text-center text-sm text-gray-500">No pins assigned.</td>
                    </tr>
                @endforelse
            </x-table>
        </div>
    </div>
</x-admin-layout>
@endsection
