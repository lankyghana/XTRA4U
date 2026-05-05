@extends('layouts.app')

@section('title', 'Order Status - ' . $order->id . ' - XTRA4U')

@section('content')
<div class="min-h-screen bg-gradient-to-br from-blue-50 via-white to-green-50 py-12">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="text-center mb-10">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full mb-4" :class="getStatusColor('{{ $order->status }}').bg">
                <svg class="w-8 h-8" :class="getStatusColor('{{ $order->status }}').text" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <h1 class="text-3xl sm:text-4xl font-bold text-gray-900">Order Status</h1>
            <p class="mt-3 text-lg text-gray-600" x-text="getStatusMessage('{{ $order->status }}')"></p>
        </div>

        <!-- Status Card -->
        <div class="bg-white rounded-2xl shadow-xl p-8 mb-8">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6 pb-6 border-b border-gray-200">
                <div>
                    <span class="text-sm text-gray-500 uppercase tracking-wide">Order ID</span>
                    <p class="font-mono font-semibold text-gray-900 text-lg mt-1">{{ $order->id }}</p>
                </div>
                <div class="flex items-center px-4 py-2 rounded-full" :class="getStatusColor('{{ $order->status }}').bg">
                    <span class="w-2 h-2 rounded-full mr-2" :class="getStatusColor('{{ $order->status }}').dot"></span>
                    <span class="text-sm font-semibold" :class="getStatusColor('{{ $order->status }}').text" x-text="getStatusLabel('{{ $order->status }}')"></span>
                </div>
            </div>

            <!-- Order Information -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 mb-8 pb-8 border-b border-gray-200">
                <div>
                    <span class="text-xs text-gray-500 uppercase tracking-wide">Service</span>
                    <p class="font-medium text-gray-900 mt-2">{{ $order->service->name ?? 'N/A' }}</p>
                </div>
                <div>
                    <span class="text-xs text-gray-500 uppercase tracking-wide">Quantity</span>
                    <p class="font-medium text-gray-900 mt-2">{{ $order->quantity }} item(s)</p>
                </div>
                <div>
                    <span class="text-xs text-gray-500 uppercase tracking-wide">Customer Name</span>
                    <p class="font-medium text-gray-900 mt-2">{{ $order->customer_name ?? 'N/A' }}</p>
                </div>
                <div>
                    <span class="text-xs text-gray-500 uppercase tracking-wide">Phone Number</span>
                    <p class="font-medium text-gray-900 mt-2">{{ $order->customer_phone }}</p>
                </div>
                <div>
                    <span class="text-xs text-gray-500 uppercase tracking-wide">Amount Paid</span>
                    <p class="font-semibold text-gray-900 mt-2">GH₵ {{ number_format($order->total_price, 2) }}</p>
                </div>
                <div>
                    <span class="text-xs text-gray-500 uppercase tracking-wide">Reference</span>
                    <p class="font-mono font-semibold text-gray-900 text-sm mt-2 break-all">{{ $order->payment_reference }}</p>
                </div>
            </div>

            <!-- Timeline -->
            <div class="mb-8 pb-8 border-b border-gray-200">
                <h3 class="font-semibold text-gray-900 mb-4">Timeline</h3>
                <div class="space-y-4">
                    <!-- Order Created -->
                    <div class="flex">
                        <div class="flex flex-col items-center mr-4">
                            <div class="flex items-center justify-center h-10 w-10 rounded-full bg-green-100">
                                <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                </svg>
                            </div>
                            <div class="h-12 w-1 bg-gray-200 my-2"></div>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-900">Order Created</p>
                            <p class="text-sm text-gray-500">{{ $order->created_at->format('M d, Y h:i A') }}</p>
                        </div>
                    </div>

                    <!-- Payment -->
                    <div class="flex">
                        <div class="flex flex-col items-center mr-4">
                            <div class="flex items-center justify-center h-10 w-10 rounded-full" :class="$order->paid_at ? 'bg-green-100' : 'bg-gray-100'">
                                <svg class="h-6 w-6" :class="$order->paid_at ? 'text-green-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                            </div>
                            <div class="h-12 w-1 bg-gray-200 my-2"></div>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-900">Payment Confirmed</p>
                            <p class="text-sm text-gray-500">
                                @if($order->paid_at)
                                    {{ $order->paid_at->format('M d, Y h:i A') }}
                                @else
                                    <span class="text-gray-400">Pending...</span>
                                @endif
                            </p>
                        </div>
                    </div>

                    <!-- Fulfilled -->
                    <div class="flex">
                        <div class="flex flex-col items-center mr-4">
                            <div class="flex items-center justify-center h-10 w-10 rounded-full" :class="$order->fulfilled_at ? 'bg-green-100' : 'bg-gray-100'">
                                <svg class="h-6 w-6" :class="$order->fulfilled_at ? 'text-green-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                                </svg>
                            </div>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-900">Delivered</p>
                            <p class="text-sm text-gray-500">
                                @if($order->fulfilled_at)
                                    {{ $order->fulfilled_at->format('M d, Y h:i A') }}
                                @else
                                    <span class="text-gray-400">Processing...</span>
                                @endif
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Status-Specific Messages -->
            @if($order->status === 'pending_payment')
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                    <p class="text-yellow-800 text-sm">Awaiting payment confirmation. Please complete the payment to proceed.</p>
                </div>
            @elseif($order->status === 'pending_stock')
                <div class="bg-orange-50 border border-orange-200 rounded-lg p-4">
                    <p class="text-orange-800 text-sm">Your order is waiting for stock availability. We'll notify you via SMS when ready.</p>
                </div>
            @elseif($order->status === 'completed')
                <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                    <p class="text-green-800 text-sm">Your results have been delivered to {{ $order->customer_phone }}. Check your SMS for details.</p>
                </div>
            @elseif($order->status === 'failed')
                <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                    <p class="text-red-800 text-sm">This order could not be completed. Please contact support for assistance.</p>
                </div>
            @endif
        </div>

        <!-- Back Button -->
        <div class="text-center">
            <a href="{{ route('result-checkers.status') }}" class="inline-flex items-center text-brand-deep-blue hover:text-brand-bright-blue font-medium transition-colors">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Back to Status Checker
            </a>
        </div>
    </div>
</div>

<script>
function getStatusColor(status) {
    const colors = {
        'pending_payment': { bg: 'bg-yellow-100', text: 'text-yellow-800', dot: 'bg-yellow-500' },
        'pending_stock': { bg: 'bg-orange-100', text: 'text-orange-800', dot: 'bg-orange-500' },
        'processing': { bg: 'bg-blue-100', text: 'text-blue-800', dot: 'bg-blue-500' },
        'completed': { bg: 'bg-green-100', text: 'text-green-800', dot: 'bg-green-500' },
        'failed': { bg: 'bg-red-100', text: 'text-red-800', dot: 'bg-red-500' },
    };
    return colors[status] || { bg: 'bg-gray-100', text: 'text-gray-800', dot: 'bg-gray-500' };
}

function getStatusLabel(status) {
    const labels = {
        'pending_payment': 'Awaiting Payment',
        'pending_stock': 'Pending Stock',
        'processing': 'Processing',
        'completed': 'Completed',
        'failed': 'Failed',
    };
    return labels[status] || 'Unknown';
}

function getStatusMessage(status) {
    const messages = {
        'pending_payment': 'Please complete your payment to proceed',
        'pending_stock': 'We are preparing your results',
        'processing': 'Your order is being processed',
        'completed': 'Your order has been completed',
        'failed': 'Your order could not be completed',
    };
    return messages[status] || 'Check your order status below';
}
</script>
@endsection
