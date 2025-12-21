@extends('layouts.vendor')

@section('title', 'Affiliate Orders - XTRA4U')

@section('content')
<x-vendor-layout :vendor="$vendor" title="Affiliate Orders" subtitle="Track earnings from products sold by resellers" active="orders">
    <div class="space-y-6">
        @if(session('success'))
            <div class="bg-gradient-to-r from-green-50 to-emerald-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg shadow-md">
                <div class="flex items-center">
                    <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                    {{ session('success') }}
                </div>
            </div>
        @endif
        
        @if(session('error'))
            <div class="bg-gradient-to-r from-red-50 to-pink-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg shadow-md">
                <div class="flex items-center">
                    <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                    </svg>
                    {{ session('error') }}
                </div>
            </div>
        @endif

        <!-- Info Banner -->
        <div class="bg-gradient-to-r from-purple-50 to-indigo-50 border border-purple-200 rounded-xl p-4">
            <div class="flex items-start">
                <svg class="w-5 h-5 text-purple-600 mt-0.5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                </svg>
                <div>
                    <h3 class="text-sm font-semibold text-purple-900">Affiliate Orders</h3>
                    <p class="text-sm text-purple-700 mt-1">These are orders for your products that were sold by your resellers. You can track these orders to monitor your earnings. The reseller is responsible for fulfilling these orders.</p>
                </div>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <div class="flex space-x-4 border-b border-gray-200">
            <a href="{{ route('vendor.orders.index') }}" class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-brand-deep-blue border-b-2 border-transparent hover:border-gray-300 transition-colors">
                My Orders
            </a>
            <a href="{{ route('vendor.orders.affiliate') }}" class="px-4 py-2 text-sm font-medium text-brand-deep-blue border-b-2 border-brand-deep-blue">
                Affiliate Orders
            </a>
        </div>
        
        <div class="bg-gradient-to-br from-white to-gray-50 rounded-xl shadow-lg border border-gray-200">
            <div class="px-6 py-6">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h1 class="text-xl font-bold text-gray-900">Affiliate Orders</h1>
                        <p class="text-sm text-gray-600">Track orders for your products sold by resellers. Resellers fulfill these orders.</p>
                    </div>
                    <p class="text-sm text-gray-500">Showing <span class="font-semibold text-purple-600">{{ $orders->count() }}</span> of <span class="font-semibold text-purple-600">{{ $orders->total() }}</span> orders</p>
                </div>

                <div class="overflow-hidden rounded-lg border border-gray-200">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gradient-to-r from-purple-50 to-indigo-50">
                                <tr>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Order ID</th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Recipient</th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Product</th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Reseller</th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Your Earning</th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Placed</th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Action</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @forelse ($orders as $order)
                                    <tr class="hover:bg-gradient-to-r hover:from-purple-50 hover:to-indigo-50 transition-all duration-200">
                                        <td class="px-6 py-4 text-sm font-semibold text-gray-900">#{{ $order->id }}</td>
                                        <td class="px-6 py-4 text-sm text-gray-900">{{ $order->recipient_phone_number }}</td>
                                        <td class="px-6 py-4 text-sm text-gray-900">{{ $order->display_product_name }}</td>
                                        <td class="px-6 py-4 text-sm">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                                                {{ $order->resellerVendor?->name ?? 'Unknown' }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 text-sm font-semibold text-green-600">GHS {{ number_format($order->owner_earning_amount, 2) }}</td>
                                        <td class="px-6 py-4 text-sm">
                                            @if($order->status === 'Completed')
                                                <x-badge variant="completed">Completed</x-badge>
                                            @elseif($order->status === 'Processing')
                                                <x-badge variant="processing">Processing</x-badge>
                                            @elseif($order->status === 'Cancelled')
                                                <x-badge variant="warning">Cancelled</x-badge>
                                            @else
                                                <x-badge variant="pending">Pending</x-badge>
                                            @endif
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500">{{ $order->created_at?->timezone(config('app.timezone'))->format('M d, Y \at g:i A') }}</td>
                                        <td class="px-6 py-4 text-sm">
                                            <span class="inline-flex items-center px-3 py-2 text-sm text-gray-600 bg-gray-100 rounded-lg">
                                                <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                                </svg>
                                                View Only
                                            </span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="px-6 py-8 text-center">
                                            <div class="flex flex-col items-center justify-center">
                                                <svg class="w-12 h-12 text-gray-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                                                </svg>
                                                <p class="text-sm font-medium text-gray-500">No affiliate orders yet.</p>
                                                <p class="text-xs text-gray-400 mt-1">When resellers sell your products, orders will appear here for tracking. The reseller fulfills these orders.</p>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                @if ($orders->hasPages())
                    <div class="mt-6">
                        {{ $orders->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-vendor-layout>
@endsection
