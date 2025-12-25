@extends('layouts.app')

@section('title', 'Payment Successful - XTRA4U')
@section('description', 'Your payment has been processed successfully')

@section('content')
<div class="min-h-screen bg-gray-50 py-8 lg:py-16">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        <!-- Success Icon & Header -->
        <div class="text-center mb-8">
            <div class="mx-auto w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mb-6">
                <svg class="w-10 h-10 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
            </div>
            <h1 class="text-3xl font-bold text-gray-900">Payment Successful!</h1>
            <p class="mt-2 text-lg text-gray-600">Your order has been processed successfully</p>
        </div>
        
        <!-- Order Details Card -->
        <x-card class="mb-8">
            <div class="bg-linear-to-r from-green-50 to-blue-50 px-6 py-4 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-gray-900">Order Details</h2>
                <p class="text-sm text-gray-600">Order #{{ $order->id }} - {{ $order->created_at->format('M d, Y \a\t g:i A') }}</p>
            </div>
            
            <div class="p-6 space-y-6">
                <!-- Order Summary -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <h3 class="text-sm font-medium text-gray-500 uppercase tracking-wide mb-3">Service Information</h3>
                        <div class="space-y-3">
                            <div class="flex justify-between">
                                <span class="text-gray-600">Service:</span>
                                <span class="font-medium">{{ $order->display_product_label }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Recipient:</span>
                                <span class="font-medium">{{ $order->recipient_phone_number }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Amount:</span>
                                <span class="font-medium text-green-600">GHS {{ number_format($order->amount_paid, 2) }}</span>
                            </div>
                        </div>
                    </div>
                    
                    <div>
                        <h3 class="text-sm font-medium text-gray-500 uppercase tracking-wide mb-3">Payment Information</h3>
                        <div class="space-y-3">
                            <div class="flex justify-between">
                                <span class="text-gray-600">Payment Method:</span>
                                <span class="font-medium">Mobile Money</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Mobile Money:</span>
                                <span class="font-medium">{{ $order->mobile_money_number }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Status:</span>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                    {{ $order->status }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Progress Steps -->
                <div class="border-t border-gray-200 pt-6">
                    <h3 class="text-sm font-medium text-gray-500 uppercase tracking-wide mb-4">Order Progress</h3>
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <div class="flex items-center justify-center w-8 h-8 bg-green-600 text-white rounded-full text-sm font-medium">
                                ✓
                            </div>
                            <span class="ml-2 text-sm font-medium text-green-600">Payment Received</span>
                        </div>
                        <div class="w-16 h-1 bg-green-200 rounded"></div>
                        <div class="flex items-center">
                            <div class="flex items-center justify-center w-8 h-8 bg-blue-600 text-white rounded-full text-sm font-medium">
                                2
                            </div>
                            <span class="ml-2 text-sm font-medium text-blue-600">Processing</span>
                        </div>
                        <div class="w-16 h-1 bg-gray-200 rounded"></div>
                        <div class="flex items-center">
                            <div class="flex items-center justify-center w-8 h-8 bg-gray-300 text-gray-600 rounded-full text-sm font-medium">
                                3
                            </div>
                            <span class="ml-2 text-sm font-medium text-gray-500">Completed</span>
                        </div>
                    </div>
                </div>
            </div>
        </x-card>
        
        <!-- What's Next Section -->
        <x-card class="mb-8">
            <div class="p-6">
                <h3 class="text-lg font-medium text-gray-900 mb-4">What happens next?</h3>
                <div class="space-y-4">
                    <div class="flex items-start">
                        <div class="shrink-0">
                            <div class="w-6 h-6 bg-blue-100 rounded-full flex items-center justify-center">
                                <span class="text-blue-600 text-xs font-medium">1</span>
                            </div>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-gray-700">
                                <strong>Processing:</strong> Our vendor will process your order within the next few minutes.
                            </p>
                        </div>
                    </div>
                    <div class="flex items-start">
                        <div class="shrink-0">
                            <div class="w-6 h-6 bg-blue-100 rounded-full flex items-center justify-center">
                                <span class="text-blue-600 text-xs font-medium">2</span>
                            </div>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-gray-700">
                                <strong>Service Delivery:</strong> The service will be delivered to {{ $order->recipient_phone_number }}.
                            </p>
                        </div>
                    </div>
                    <div class="flex items-start">
                        <div class="shrink-0">
                            <div class="w-6 h-6 bg-blue-100 rounded-full flex items-center justify-center">
                                <span class="text-blue-600 text-xs font-medium">3</span>
                            </div>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-gray-700">
                                <strong>Confirmation:</strong> You'll receive an SMS confirmation once the service is delivered.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </x-card>
        
        <!-- Contact Support -->
        <x-card class="bg-blue-50 border-blue-200">
            <div class="p-6">
                <div class="flex items-start">
                    <div class="shrink-0">
                        <svg class="h-6 w-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192L5.636 18.364M12 2.196l1.75 5.25 5.25 1.75-5.25 1.75-1.75 5.25-1.75-5.25-5.25-1.75 5.25-1.75 1.75-5.25z"/>
                        </svg>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-blue-900">Need Help?</h3>
                        <p class="mt-1 text-sm text-blue-700">
                            If you have any questions about your order or need assistance, our support team is here to help.
                        </p>
                        <div class="mt-3">
                            <x-button variant="outline" size="sm" class="border-blue-300 text-blue-700 hover:bg-blue-100">
                                Contact Support
                            </x-button>
                        </div>
                    </div>
                </div>
            </div>
        </x-card>
        
        <!-- Action Buttons -->
        <div class="flex flex-col sm:flex-row gap-4 justify-center">
            <x-button href="{{ route('storefront.vendor', $order->vendor) }}" variant="primary" size="lg" class="w-full sm:w-auto">
                Continue Shopping
            </x-button>
            <x-button href="{{ route('checkout.receipt', $order) }}" variant="outline" size="lg" class="w-full sm:w-auto" target="_blank" rel="noopener">
                Print Receipt
            </x-button>
        </div>
    </div>
</div>
@endsection

@push('styles')
<style>
@media print {
    body * {
        visibility: hidden;
    }
    .print-area, .print-area * {
        visibility: visible;
    }
    .print-area {
        position: absolute;
        left: 0;
        top: 0;
    }
    .no-print {
        display: none !important;
    }
}
</style>
@endpush