@extends('layouts.vendor')

@section('title', 'Orders - XTRA4U')

{{--
    Visual redesign only — every route(), the search form's hidden-field
    passthrough, the status <select> (including its disabled conditions)
    and its `onchange="this.form.submit()"` auto-submit are unchanged.
--}}
@section('content')
<x-vendor-layout :vendor="$vendor" title="Orders" subtitle="All orders you've fulfilled" active="orders">
    <div class="space-y-6">
        @if(session('success'))
            <div class="flex items-center gap-3 bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl">
                <svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                </svg>
                <span class="text-sm">{{ session('success') }}</span>
            </div>
        @endif

        @if(session('error'))
            <div class="flex items-center gap-3 bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-xl">
                <svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                </svg>
                <span class="text-sm">{{ session('error') }}</span>
            </div>
        @endif

        <!-- Navigation Tabs -->
        <div class="flex gap-1 border-b border-gray-200">
            <a href="{{ route('vendor.orders.index') }}" class="px-4 py-2.5 text-sm font-semibold text-brand-violet border-b-2 border-brand-violet -mb-px">
                My Orders
            </a>
            <a href="{{ route('vendor.orders.affiliate') }}" class="px-4 py-2.5 text-sm font-medium text-gray-500 hover:text-brand-violet border-b-2 border-transparent hover:border-gray-300 transition-colors -mb-px">
                Affiliate Orders
            </a>
        </div>

        <div class="bg-white rounded-xl border border-gray-200">
            <div class="px-6 py-6">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-6">
                    <div>
                        <h1 class="text-xl font-bold text-gray-900">Orders</h1>
                        <p class="text-sm text-gray-500">Browse every order you have processed on XTRA4U.</p>
                    </div>
                    <p class="text-sm text-gray-500">Showing <span class="font-semibold text-brand-violet">{{ $orders->count() }}</span> of <span class="font-semibold text-brand-violet">{{ $orders->total() }}</span> orders</p>
                </div>

                <!-- Search -->
                <form method="GET" action="{{ route('vendor.orders.index') }}" class="mb-6">
                    @foreach(request()->except(['page', 'q']) as $key => $value)
                        @if(is_array($value))
                            @foreach($value as $arrayValue)
                                <input type="hidden" name="{{ $key }}[]" value="{{ $arrayValue }}">
                            @endforeach
                        @else
                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                        @endif
                    @endforeach

                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex-1">
                            <label for="orders-search" class="sr-only">Search orders</label>
                            <div class="relative">
                                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                    <svg class="h-5 w-5 text-gray-400" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M9 3a6 6 0 104.472 10.03l2.249 2.25a1 1 0 001.414-1.415l-2.25-2.249A6 6 0 009 3zm-4 6a4 4 0 118 0 4 4 0 01-8 0z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                                <input
                                    id="orders-search"
                                    name="q"
                                    value="{{ request('q') }}"
                                    placeholder="Search by Order ID, phone number, or product"
                                    class="block w-full rounded-lg border border-gray-300 bg-white pl-10 pr-3 py-2.5 text-sm shadow-sm focus:border-brand-violet focus:ring-brand-violet"
                                />
                            </div>
                        </div>

                        <div class="flex gap-2">
                            <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-brand-violet px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-brand-violet-deep transition-colors">
                                Search
                            </button>

                            @if((string) request('q') !== '')
                                <a href="{{ route('vendor.orders.index') }}" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">
                                    Clear
                                </a>
                            @endif
                        </div>
                    </div>
                </form>

                <div class="overflow-hidden rounded-xl border border-gray-100">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-100">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Order ID</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Recipient</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Product</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Affiliate Vendor</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Amount</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">External API</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Placed</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($orders as $order)
                                    @php
                                        $canUpdateStatus = (bool) $order->is_reseller_order
                                            ? ((int) $order->owner_vendor_id === (int) $vendor->id)
                                            : ((int) $order->vendor_id === (int) $vendor->id);
                                        $isAffiliateForViewer = (bool) $order->is_reseller_order;

                                        $externalStatus = (string) ($order->external_fulfillment_status ?? '');
                                        $externalError = (string) ($order->external_fulfillment_last_error ?? '');
                                        $isExternallyProcessingOrDone = in_array($externalStatus, ['processing', 'succeeded'], true);
                                    @endphp
                                    <tr class="hover:bg-violet-50/40 transition-colors duration-150">
                                        <td class="px-6 py-4 text-sm font-semibold text-gray-900">
                                            <div class="flex items-center gap-2">
                                                <span>#{{ $order->id }}</span>
                                                @if($isAffiliateForViewer)
                                                    <x-badge variant="processing" size="sm">Affiliate Order</x-badge>
                                                @endif
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-900">{{ $order->recipient_phone_number }}</td>
                                        <td class="px-6 py-4 text-sm text-gray-900">{{ $order->display_product_label }}</td>
                                        <td class="px-6 py-4 text-sm text-gray-900">
                                            @if($order->is_reseller_order && $order->resellerVendor)
                                                <span class="text-brand-violet-deep font-medium">{{ $order->resellerVendor->name }}</span>
                                            @else
                                                -
                                            @endif
                                        </td>
                                        <td class="px-6 py-4 text-sm font-semibold text-gray-900">GHS {{ number_format($order->amount_paid, 2) }}</td>
                                        <td class="px-6 py-4 text-sm">
                                            @if($order->status === 'Completed')
                                                <x-badge variant="completed">Completed</x-badge>
                                            @elseif($order->status === 'Processing')
                                                <x-badge variant="processing">Processing</x-badge>
                                            @elseif($order->status === 'Verifying')
                                                <x-badge variant="processing">Verifying</x-badge>
                                            @elseif($order->status === 'On Hold')
                                                <x-badge variant="default">On Hold</x-badge>
                                            @elseif($order->status === 'Cancelled')
                                                <x-badge variant="warning">Cancelled</x-badge>
                                            @elseif($order->status === 'Refunded')
                                                <x-badge variant="warning">Refunded</x-badge>
                                            @elseif($order->status === 'Failed')
                                                <x-badge variant="failed">Failed</x-badge>
                                            @else
                                                {{-- Vendors never see Pending orders (excluded at the query level),
                                                     so this only guards against an unexpected/future status value —
                                                     show it verbatim rather than mislabeling it. --}}
                                                <x-badge variant="default">{{ $order->status }}</x-badge>
                                            @endif
                                        </td>
                                        <td class="px-6 py-4 text-sm">
                                            @if($externalStatus === 'succeeded')
                                                <x-badge variant="completed">Sent to API</x-badge>
                                            @elseif($externalStatus === 'processing')
                                                <x-badge variant="processing">Sending</x-badge>
                                            @elseif($externalStatus === 'failed')
                                                <span title="{{ $externalError !== '' ? $externalError : 'External fulfillment failed' }}">
                                                    <x-badge variant="warning">Failed</x-badge>
                                                </span>
                                            @else
                                                <x-badge variant="pending">Not sent</x-badge>
                                            @endif
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500">{{ $order->created_at?->format('M d, Y g:i A') }}</td>
                                        <td class="px-6 py-4 text-sm">
                                        @if($canUpdateStatus)
                                            <form method="POST" action="{{ route('vendor.orders.update-status', $order) }}" class="inline-block">
                                                @csrf
                                                @method('PATCH')
                                                <select name="status"
                                                    onchange="this.form.submit()"
                                                    class="text-sm border-gray-300 rounded-lg focus:ring-brand-violet focus:border-brand-violet px-3 py-2 font-medium shadow-sm"
                                                    {{ $order->status === 'Completed' || $order->status === 'Cancelled' || $order->status === 'Refunded' || $isExternallyProcessingOrDone ? 'disabled' : '' }}>
                                                    <option value="Pending" {{ $order->status === 'Pending' ? 'selected' : '' }}>Pending</option>
                                                    <option value="Processing" {{ $order->status === 'Processing' ? 'selected' : '' }}>Processing</option>
                                                    <option value="Verifying" {{ $order->status === 'Verifying' ? 'selected' : '' }}>Verifying</option>
                                                    <option value="On Hold" {{ $order->status === 'On Hold' ? 'selected' : '' }}>On Hold</option>
                                                    <option value="Completed" {{ $order->status === 'Completed' ? 'selected' : '' }}>Completed</option>
                                                    <option value="Cancelled" {{ $order->status === 'Cancelled' ? 'selected' : '' }}>Cancelled</option>
                                                </select>
                                            </form>
                                        @else
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                                                View only
                                            </span>
                                        @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="9" class="px-6 py-16">
                                            {{-- The row's real width spans all 9 (wide) columns, so a plain
                                                 centered block would land off-screen on mobile. Sticking this
                                                 inner wrapper to the scroll container's left edge, and capping
                                                 its width to the viewport, keeps it visible without scrolling. --}}
                                            <div class="sticky left-0 w-screen max-w-[calc(100vw-3rem)] text-center">
                                                <svg class="w-10 h-10 mx-auto text-gray-300 mb-3" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
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
