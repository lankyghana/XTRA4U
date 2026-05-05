@extends('layouts.app')

@section('title', 'Order Completed - Results Checker - XTRA4U')

@section('content')
<div class="min-h-screen bg-gradient-to-br from-blue-50 via-white to-green-50 py-12">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Success Animation -->
        <div class="text-center mb-10">
            <div class="inline-flex items-center justify-center w-20 h-20 bg-green-100 rounded-full mb-6 animate-bounce">
                <svg class="w-10 h-10 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <h1 class="text-3xl sm:text-4xl font-bold text-gray-900">Order Completed!</h1>
            <p class="mt-3 text-lg text-gray-600">Your results have been delivered successfully.</p>
        </div>

        <!-- Success Card -->
        <div class="bg-white rounded-2xl shadow-xl p-8 mb-8">
            <div class="text-center">
                <!-- Confirmation Message -->
                <div class="bg-green-50 border border-green-200 rounded-lg p-6 mb-8">
                    <div class="flex items-start">
                        <svg class="w-6 h-6 text-green-600 mr-3 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                        </path>
                    </svg>
                        <div class="text-left">
                            <h2 class="font-semibold text-green-900 text-lg">Results Delivered</h2>
                            <p class="text-green-700 text-sm mt-1">Your results have been sent to your phone number. You should receive an SMS shortly.</p>
                        </div>
                    </div>
                </div>
                
                <!-- Order Details -->
                <div class="bg-gray-50 rounded-lg p-6 mb-8 text-left">
                    <h3 class="font-semibold text-gray-900 mb-4">Order Details</h3>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <span class="text-sm text-gray-500 uppercase tracking-wide">Order ID</span>
                            <p class="font-mono font-semibold text-gray-900 mt-1">{{ $order->id }}</p>
                        </div>
                        <div>
                            <span class="text-sm text-gray-500 uppercase tracking-wide">Service</span>
                            <p class="font-medium text-gray-900 mt-1">{{ $order->service->name ?? 'N/A' }}</p>
                        </div>
                        <div>
                            <span class="text-sm text-gray-500 uppercase tracking-wide">Quantity</span>
                            <p class="font-medium text-gray-900 mt-1">{{ $order->quantity }} item(s)</p>
                        </div>
                        <div>
                            <span class="text-sm text-gray-500 uppercase tracking-wide">Recipient Phone</span>
                            <p class="font-medium text-gray-900 mt-1">{{ $order->customer_phone }}</p>
                        </div>
                        <div>
                            <span class="text-sm text-gray-500 uppercase tracking-wide">Amount Paid</span>
                            <p class="font-semibold text-gray-900 mt-1">GH₵ {{ number_format($order->total_price, 2) }}</p>
                        </div>
                        <div>
                            <span class="text-sm text-gray-500 uppercase tracking-wide">Completed On</span>
                            <p class="font-medium text-gray-900 mt-1">{{ $order->fulfilled_at?->format('M d, Y h:i A') ?? 'Just now' }}</p>
                        </div>
                    </div>
                </div>

                <!-- What to Expect -->
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mb-8 text-left">
                    <h3 class="font-semibold text-blue-900 mb-3">What You'll Receive</h3>
                    <ul class="space-y-3 text-sm text-blue-800">
                        <li class="flex items-start">
                            <svg class="w-5 h-5 text-blue-600 mr-3 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                            <span><strong>SMS Message:</strong> Your results have been sent via SMS to {{ $order->customer_phone }}</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-5 h-5 text-blue-600 mr-3 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                            <span><strong>PIN & SERIAL:</strong> Check your SMS for the PIN and serial number to access your results</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-5 h-5 text-blue-600 mr-3 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                            <span><strong>Reference:</strong> Save this order reference for future inquiries: <code class="text-xs bg-white px-2 py-1 rounded font-mono">{{ $order->payment_reference }}</code></span>
                        </li>
                    </ul>
                </div>

                <!-- Tips for Safety -->
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6 mb-8 text-left">
                    <h3 class="font-semibold text-yellow-900 mb-3">⚠️ Safety Tips</h3>
                    <ul class="space-y-2 text-sm text-yellow-800">
                        <li class="flex items-start">
                            <span class="mr-2">•</span>
                            <span>Never share your PIN and SERIAL with anyone</span>
                        </li>
                        <li class="flex items-start">
                            <span class="mr-2">•</span>
                            <span>XTRA4U staff will never ask you for your PIN</span>
                        </li>
                        <li class="flex items-start">
                            <span class="mr-2">•</span>
                            <span>Keep your SMS message safe for your records</span>
                        </li>
                    </ul>
                </div>

                <!-- Action Buttons -->
                <div class="flex flex-col sm:flex-row gap-4">
                    <a href="{{ route('result-checkers.status') }}" class="flex-1 inline-flex items-center justify-center px-6 py-3 bg-brand-deep-blue text-white font-semibold rounded-lg hover:opacity-90 transition-opacity">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                        Check Another Order
                    </a>
                    <a href="{{ route('storefront.index') }}" class="flex-1 inline-flex items-center justify-center px-6 py-3 bg-gray-200 text-gray-700 font-semibold rounded-lg hover:bg-gray-300 transition-colors">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12a9 9 0 019-9 9.75 9.75 0 016.74 2.74L21 8M3 12a9 9 0 0118 0m0 0a8.949 8.949 0 01-2.12 5.25m0 0a9 9 0 01-12.74 0m11.995-5.25H9"/>
                        </svg>
                        Return Home
                    </a>
                </div>

                <!-- Help Section -->
                <div class="mt-8 pt-8 border-t border-gray-200">
                    <p class="text-gray-600 text-sm">
                        Need help? <a href="mailto:support@xtra4u.com" class="text-brand-deep-blue hover:text-brand-bright-blue font-semibold">Contact our support team</a>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
