@extends('layouts.vendor')

@section('title', 'Orders - XTRA4U')

@section('content')
<x-vendor-layout :vendor="$vendor" title="Orders" subtitle="All orders you've fulfilled" active="orders">
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

        <!-- Navigation Tabs -->
        <div class="flex space-x-4 border-b border-gray-200">
            <a href="{{ route('vendor.orders.index') }}" class="px-4 py-2 text-sm font-medium text-brand-deep-blue border-b-2 border-brand-deep-blue">
                My Orders
            </a>
            <a href="{{ route('vendor.orders.affiliate') }}" class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-brand-deep-blue border-b-2 border-transparent hover:border-gray-300 transition-colors">
                Affiliate Orders
            </a>
        </div>
        
        <div class="bg-gradient-to-br from-white to-gray-50 rounded-xl shadow-lg border border-gray-200">
            <div class="px-6 py-6">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h1 class="text-xl font-bold text-gray-900">Orders</h1>
                        <p class="text-sm text-gray-600">Browse every order you have processed on XTRA4U.</p>
                    </div>
                    <p class="text-sm text-gray-500">Showing <span class="font-semibold text-brand-deep-blue">{{ $orders->count() }}</span> of <span class="font-semibold text-brand-deep-blue">{{ $orders->total() }}</span> orders</p>
                </div>

                <div class="overflow-hidden rounded-lg border border-gray-200">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gradient-to-r from-gray-50 to-slate-100">
                                <tr>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Order ID</th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Recipient</th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Product</th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Amount</th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Role</th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Placed</th>
                                    <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Action</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @forelse ($orders as $order)
                                    @php
                                        $isAffiliateOwnerView = $order->is_reseller_order && $order->owner_vendor_id === $vendor->id;
                                        $isResellerFulfillment = $order->is_reseller_order && $order->vendor_id === $vendor->id;
                                    @endphp
                                    <tr class="hover:bg-gradient-to-r hover:from-blue-50 hover:to-purple-50 transition-all duration-200">
                                        <td class="px-6 py-4 text-sm font-semibold text-gray-900">#{{ $order->id }}</td>
                                        <td class="px-6 py-4 text-sm text-gray-900">{{ $order->recipient_phone_number }}</td>
                                        <td class="px-6 py-4 text-sm text-gray-900">{{ $order->display_product_name }}</td>
                                        <td class="px-6 py-4 text-sm font-semibold text-gray-900">GHS {{ number_format($order->amount_paid, 2) }}</td>
                                        <td class="px-6 py-4 text-sm">
                                            @if($isAffiliateOwnerView)
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">Owner (Affiliate)</span>
                                                <p class="text-xs text-gray-500 mt-1">Reseller: {{ $order->resellerVendor?->name ?? 'Unknown' }}</p>
                                            @elseif($isResellerFulfillment)
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">Reseller</span>
                                                <p class="text-xs text-gray-500 mt-1">Owner: {{ $order->ownerVendor?->business_name ?? $order->ownerVendor?->name ?? 'Original Vendor' }}</p>
                                            @else
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Direct</span>
                                            @endif
                                        </td>
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
                                            @if($isAffiliateOwnerView)
                                                <span class="inline-flex items-center px-3 py-2 text-sm text-gray-600 bg-gray-100 rounded-lg">
                                                    <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                                    </svg>
                                                    View Only
                                                </span>
                                            @else
                                                @php $statusLocked = in_array($order->status, ['Completed', 'Cancelled']); @endphp
                                                <form method="POST" action="{{ route('vendor.orders.update-status', $order) }}" class="inline-block">
                                                    @csrf
                                                    @method('PATCH')
                                                    <select name="status" 
                                                            onchange="this.form.submit()" 
                                                            class="text-sm border-gray-300 rounded-lg focus:ring-brand-bright-blue focus:border-brand-bright-blue px-3 py-2 font-medium shadow-sm"
                                                            {{ $statusLocked ? 'disabled' : '' }}>
                                                        <option value="Pending" {{ $order->status === 'Pending' ? 'selected' : '' }}>Pending</option>
                                                        <option value="Processing" {{ $order->status === 'Processing' ? 'selected' : '' }}>Processing</option>
                                                        <option value="Completed" {{ $order->status === 'Completed' ? 'selected' : '' }}>Completed</option>
                                                        <option value="Cancelled" {{ $order->status === 'Cancelled' ? 'selected' : '' }}>Cancelled</option>
                                                    </select>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="px-6 py-8 text-center">
                                            <div class="flex flex-col items-center justify-center">
                                                <svg class="w-12 h-12 text-gray-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                                                </svg>
                                                <p class="text-sm font-medium text-gray-500">No orders yet.</p>
                                                <p class="text-xs text-gray-400 mt-1">Orders will appear here when customers make purchases.</p>
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