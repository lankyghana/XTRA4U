@extends('layouts.vendor')

@section('title', 'Orders - XTRA4U')

@section('content')
<x-vendor-layout :vendor="$vendor" title="Orders" subtitle="All orders you've fulfilled" active="orders">
    <div class="space-y-6">
        <x-card>
            <div class="px-4 py-5 sm:p-6">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h1 class="text-lg font-semibold text-gray-900">Orders</h1>
                        <p class="text-sm text-gray-500">Browse every order you have processed on XTRA4U.</p>
                    </div>
                    <p class="text-sm text-gray-500">Showing {{ $orders->count() }} of {{ $orders->total() }} orders</p>
                </div>

                <div class="overflow-x-auto">
                    <x-table :headers="['Order ID', 'Recipient', 'Amount', 'Status', 'Placed']">
                        @forelse ($orders as $order)
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 text-sm font-medium text-gray-900">#{{ $order->id }}</td>
                                <td class="px-6 py-4 text-sm text-gray-900">{{ $order->recipient_phone_number }}</td>
                                <td class="px-6 py-4 text-sm text-gray-900">GHS {{ number_format($order->amount_paid, 2) }}</td>
                                <td class="px-6 py-4 text-sm">
                                    <x-badge :variant="$order->status === 'Completed' ? 'completed' : 'pending'">{{ $order->status }}</x-badge>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $order->created_at?->format('M d, Y') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-4 text-center text-sm text-gray-500">No orders yet.</td>
                            </tr>
                        @endforelse
                    </x-table>
                </div>

                @if ($orders->hasPages())
                    <div class="mt-4">
                        {{ $orders->links() }}
                    </div>
                @endif
            </div>
        </x-card>
    </div>
</x-vendor-layout>
@endsection