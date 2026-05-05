@extends('layouts.app')

@section('title', 'Order Status - Results Checker - XTRA4U')

@section('content')
<div class="min-h-screen bg-gradient-to-br from-blue-50 via-white to-green-50 py-12">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="text-center mb-10">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-gradient-to-r from-orange-400 to-orange-600 rounded-full mb-4">
                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <h1 class="text-3xl sm:text-4xl font-bold text-gray-900">Order Pending Stock</h1>
            <p class="mt-3 text-lg text-gray-600">Your order is currently waiting for stock availability.</p>
        </div>

        <!-- Status Card -->
        <div class="bg-white rounded-2xl shadow-xl p-8 mb-8">
            <div class="text-center">
                <div class="inline-flex items-center justify-center w-20 h-20 bg-orange-100 rounded-full mb-6">
                    <svg class="w-10 h-10 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                
                <h2 class="text-2xl font-bold text-gray-900 mb-2">We're Getting Your Results</h2>
                <p class="text-gray-600 mb-6">Your results are being prepared and will be delivered to your phone as soon as they're available.</p>
                
                <!-- Order Details -->
                <div class="bg-gray-50 rounded-lg p-6 mb-6 text-left">
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
                            <span class="text-sm text-gray-500 uppercase tracking-wide">Status</span>
                            <p class="font-medium text-orange-600 mt-1">Pending Stock</p>
                        </div>
                    </div>
                </div>

                <p class="text-gray-600 text-sm mb-6">
                    Submitted on: <strong>{{ $order->created_at->format('M d, Y \a\t h:i A') }}</strong>
                </p>

                <!-- Tips -->
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mb-6 text-left">
                    <h3 class="font-semibold text-blue-900 mb-3">What's Next?</h3>
                    <ul class="space-y-2 text-sm text-blue-800">
                        <li class="flex items-start">
                            <svg class="w-5 h-5 text-blue-600 mr-3 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                            <span>We are preparing your results</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-5 h-5 text-blue-600 mr-3 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                            <span>You will receive an SMS with your results when ready</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-5 h-5 text-blue-600 mr-3 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                            <span>Keep your phone available to receive notifications</span>
                        </li>
                    </ul>
                </div>

                <!-- Action Buttons -->
                <div class="flex flex-col sm:flex-row gap-4">
                    <a href="{{ route('result-checkers.status') }}" class="flex-1 inline-flex items-center justify-center px-6 py-3 bg-brand-deep-blue text-white font-semibold rounded-lg hover:opacity-90 transition-opacity">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                        Check Status Again
                    </a>
                    <a href="{{ route('storefront.index') }}" class="flex-1 inline-flex items-center justify-center px-6 py-3 bg-gray-200 text-gray-700 font-semibold rounded-lg hover:bg-gray-300 transition-colors">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12a9 9 0 019-9 9.75 9.75 0 016.74 2.74L21 8M3 12a9 9 0 0118 0m0 0a8.949 8.949 0 01-2.12 5.25m0 0a9 9 0 01-12.74 0m11.995-5.25H9"/>
                        </svg>
                        Return Home
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
