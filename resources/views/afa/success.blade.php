@extends('layouts.app')

@section('title', 'Registration Successful - AFA')
@section('description', 'Your AFA registration has been submitted successfully.')

@section('content')
<div class="min-h-screen bg-gradient-to-br from-green-50 via-white to-blue-50 py-12">
    <div class="max-w-lg mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Success Card -->
        <div class="bg-white rounded-2xl shadow-xl overflow-hidden text-center">
            <!-- Success Icon -->
            <div class="bg-gradient-to-r from-green-500 to-green-600 py-8">
                <div class="inline-flex items-center justify-center w-20 h-20 bg-white rounded-full">
                    <svg class="w-12 h-12 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
            </div>

            <div class="p-6 sm:p-8">
                <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Registration Successful!</h1>
                <p class="mt-3 text-gray-600">Your AFA registration has been submitted and is pending review.</p>

                <!-- Reference Card -->
                <div class="mt-6 bg-gray-50 rounded-xl p-4">
                    <p class="text-sm text-gray-500 mb-1">Reference Number</p>
                    <p class="text-2xl font-mono font-bold text-gray-900">{{ $registration->reference }}</p>
                    <p class="mt-2 text-xs text-gray-500">Save this reference to track your registration status</p>
                </div>

                <!-- Registration Details -->
                <div class="mt-6 text-left space-y-3">
                    <div class="flex justify-between py-2 border-b border-gray-100">
                        <span class="text-gray-500">Name</span>
                        <span class="font-medium text-gray-900">{{ $registration->full_name }}</span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-gray-100">
                        <span class="text-gray-500">Phone</span>
                        <span class="font-medium text-gray-900">{{ $registration->phone_number }}</span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-gray-100">
                        <span class="text-gray-500">Location</span>
                        <span class="font-medium text-gray-900">{{ $registration->location }}, {{ $registration->region }}</span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-gray-100">
                        <span class="text-gray-500">Amount Paid</span>
                        <span class="font-bold text-green-600">GH₵ {{ number_format($registration->amount, 2) }}</span>
                    </div>
                    <div class="flex justify-between py-2">
                        <span class="text-gray-500">Status</span>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $registration->status_color['bg'] }} {{ $registration->status_color['text'] }}">
                            {{ $registration->status_label }}
                        </span>
                    </div>
                </div>

                <!-- What's Next -->
                <div class="mt-6 bg-blue-50 rounded-xl p-4 text-left">
                    <h3 class="font-semibold text-blue-900 flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        What's Next?
                    </h3>
                    <ul class="mt-2 text-sm text-blue-800 space-y-1">
                        <li>• Your registration is now in the vendor's queue</li>
                        <li>• The vendor will review and process your request</li>
                        <li>• You'll receive an SMS notification when completed</li>
                        <li>• Use your reference number to check status anytime</li>
                    </ul>
                </div>

                <!-- Action Buttons -->
                <div class="mt-8 space-y-3">
                    <a 
                        href="{{ route('order.status') }}"
                        class="block w-full px-6 py-3 bg-gray-100 text-gray-700 font-medium rounded-xl hover:bg-gray-200 transition-colors"
                    >
                        Check Registration Status
                    </a>
                    <a 
                        href="{{ route('storefront.vendor', $registration->vendor->vendor_code) }}"
                        class="block w-full px-6 py-3 bg-green-600 text-white font-medium rounded-xl hover:bg-green-700 transition-colors"
                    >
                        Back to {{ $registration->vendor->name }}
                    </a>
                </div>
            </div>
        </div>

        <!-- Contact Support -->
        @if($registration->vendor->phone_number)
        <div class="mt-6 text-center">
            <p class="text-sm text-gray-500">
                Questions about your registration?
            </p>
            <a 
                href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $registration->vendor->phone_number) }}?text=Hello, I just completed an AFA registration with reference {{ $registration->reference }}. I would like to know the status."
                target="_blank"
                class="inline-flex items-center mt-2 text-green-600 hover:text-green-700 font-medium"
            >
                <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/>
                </svg>
                Contact vendor on WhatsApp
            </a>
        </div>
        @endif
    </div>
</div>
@endsection
