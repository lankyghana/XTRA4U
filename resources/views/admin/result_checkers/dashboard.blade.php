@extends('layouts.admin')

@section('content')
<x-admin-layout title="Result Checkers" subtitle="Stock visibility and service totals" active="result-checkers">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">Stock Overview</h2>
                <p class="text-sm text-gray-500">Track available inventory per result checker service.</p>
            </div>
            <a href="{{ route('admin.result-checkers.pins.index') }}" class="inline-flex items-center px-4 py-2 bg-brand-deep-blue text-white rounded-md text-sm font-semibold hover:bg-opacity-90">
                Manage PINs
            </a>
        </div>

        <x-table :headers="['Service', 'Total Pins', 'Used Pins', 'Remaining Stock']">
            @forelse ($services as $service)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $service->name }}</td>
                    <td class="px-6 py-4 text-sm text-gray-900">{{ $service->total_pins ?? 0 }}</td>
                    <td class="px-6 py-4 text-sm text-gray-900">{{ $service->used_pins ?? 0 }}</td>
                    <td class="px-6 py-4 text-sm font-semibold text-gray-900">{{ $service->available_pins ?? 0 }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="px-6 py-4 text-center text-sm text-gray-500">No result checker services found.</td>
                </tr>
            @endforelse
        </x-table>

        <div class="mt-12 flex items-center justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">Recent Orders</h2>
                <p class="text-sm text-gray-500">Latest result checker orders across all vendors.</p>
            </div>
            <a href="{{ route('admin.result-checkers.orders.index') }}" class="inline-flex items-center text-sm font-medium text-brand-deep-blue hover:text-brand-bright-blue">
                View All Orders
                <svg class="ml-1 w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
            </a>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mt-4">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Order ID</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Service</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($recentOrders ?? [] as $order)
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                <a href="{{ route('admin.result-checkers.orders.show', $order) }}" class="text-brand-deep-blue hover:underline">
                                    #{{ $order->id }}
                                </a>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                {{ $order->customer_phone }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                {{ $order->service->name ?? 'N/A' }} (x{{ $order->quantity }})
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                    @if($order->status === 'completed') bg-green-100 text-green-800
                                    @elseif($order->status === 'failed') bg-red-100 text-red-800
                                    @elseif($order->status === 'processing') bg-blue-100 text-blue-800
                                    @else bg-yellow-100 text-yellow-800 @endif">
                                    {{ ucfirst(str_replace('_', ' ', $order->status)) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <a href="{{ route('admin.result-checkers.orders.show', $order) }}"
                                   class="text-brand-deep-blue hover:text-brand-bright-blue font-semibold">
                                    View
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-4 text-center text-sm text-gray-500">No recent orders found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-admin-layout>
@endsection
