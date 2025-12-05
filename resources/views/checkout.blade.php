@extends('layouts.app')

@section('title', 'Checkout - XTRA4U')
@section('description', 'Complete your purchase securely with XTRA4U')

@section('content')
<div
    class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-purple-50 py-8 lg:py-12"
    x-data='paymentForm(@json($vendors))'
>
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="text-center mb-10">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-gradient-to-br from-purple-600 to-indigo-600 rounded-2xl shadow-lg shadow-purple-200 mb-4">
                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path>
                </svg>
            </div>
            <h1 class="text-3xl font-bold text-gray-900">Complete Your Purchase</h1>
            <p class="mt-2 text-gray-500">Secure checkout powered by XTRA4U</p>
        </div>
        
        <!-- Progress Steps -->
        <div class="mb-10">
            <div class="flex items-center justify-center">
                <div class="flex items-center">
                    <div class="flex items-center justify-center w-10 h-10 bg-gradient-to-br from-purple-600 to-indigo-600 text-white rounded-full text-sm font-bold shadow-lg shadow-purple-200">
                        1
                    </div>
                    <span class="ml-3 text-sm font-semibold text-purple-700">Service Details</span>
                </div>
                <div class="w-12 sm:w-20 h-1 bg-gradient-to-r from-purple-300 to-gray-200 rounded mx-3"></div>
                <div class="flex items-center">
                    <div class="flex items-center justify-center w-10 h-10 bg-gray-200 text-gray-500 rounded-full text-sm font-bold">
                        2
                    </div>
                    <span class="ml-3 text-sm font-medium text-gray-400 hidden sm:inline">Payment</span>
                </div>
                <div class="w-12 sm:w-20 h-1 bg-gray-200 rounded mx-3"></div>
                <div class="flex items-center">
                    <div class="flex items-center justify-center w-10 h-10 bg-gray-200 text-gray-500 rounded-full text-sm font-bold">
                        3
                    </div>
                    <span class="ml-3 text-sm font-medium text-gray-400 hidden sm:inline">Done</span>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Main Form Column -->
            <div class="lg:col-span-2 space-y-6">
                @if ($errors->any())
                    <div class="bg-red-50 border border-red-200 rounded-2xl p-5">
                        <div class="flex gap-4">
                            <div class="flex-shrink-0 w-10 h-10 bg-red-100 rounded-xl flex items-center justify-center">
                                <svg class="h-5 w-5 text-red-500" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-red-800">Please correct the following errors:</h3>
                                <ul class="mt-2 text-sm text-red-700 list-disc list-inside space-y-1">
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    </div>
                @endif

                <form method="POST" 
                      action="{{ route('purchase') }}" 
                      @submit.prevent="submitPayment($event)"
                      class="space-y-6">
                    @csrf
                    <input type="hidden" name="service_purchased" :value="selectedService?.name || ''">
                    <input type="hidden" name="amount_paid" :value="selectedService?.price || ''">
                    <input type="hidden" name="vendor_service_id" :value="selectedService?.original_product_id || selectedService?.id || ''">
                    <input type="hidden" name="is_reseller_product" :value="selectedService?.is_reseller_product ? '1' : '0'">
                    <input type="hidden" name="reseller_product_id" :value="selectedService?.reseller_product_id || ''">
                    
                    <!-- Vendor Selection Card -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="bg-gradient-to-r from-purple-50 to-indigo-50 px-6 py-4 border-b border-gray-100">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-white rounded-xl flex items-center justify-center shadow-sm">
                                    <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h2 class="text-lg font-semibold text-gray-900">Choose Vendor</h2>
                                    <p class="text-sm text-gray-500">Select a vendor to view their services</p>
                                </div>
                            </div>
                        </div>
                        <div class="p-6">
                            <select
                                id="vendor_id"
                                name="vendor_id"
                                x-model="selectedVendorId"
                                @change="handleVendorChange()"
                                required
                                class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-gray-900 focus:bg-white focus:border-purple-500 focus:ring-2 focus:ring-purple-200 transition-all"
                            >
                                <option value="">Select a vendor...</option>
                                @foreach ($vendors as $vendor)
                                    <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
                                @endforeach
                            </select>
                            <p class="mt-3 text-sm text-gray-500 flex items-center gap-2" x-show="selectedVendor" x-cloak>
                                <svg class="w-4 h-4 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                </svg>
                                <span class="font-medium" x-text="selectedVendor?.email"></span>
                                <span class="text-gray-300">•</span>
                                <span x-text="selectedVendor?.phone_number"></span>
                            </p>
                        </div>
                    </div>

                    <!-- Service Selection Card -->
                    <div x-show="selectedVendor" x-cloak class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="bg-gradient-to-r from-green-50 to-emerald-50 px-6 py-4 border-b border-gray-100">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 bg-white rounded-xl flex items-center justify-center shadow-sm">
                                        <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
                                        </svg>
                                    </div>
                                    <div>
                                        <h2 class="text-lg font-semibold text-gray-900">Select Service</h2>
                                        <p class="text-sm text-gray-500">Choose from <span x-text="selectedVendor?.name"></span>'s offerings</p>
                                    </div>
                                </div>
                                <span class="px-3 py-1 bg-white rounded-full text-sm font-medium text-gray-600 shadow-sm" x-text="selectedVendor?.products.length + ' available'"></span>
                            </div>
                        </div>
                        <div class="p-6">
                            <template x-if="selectedVendor?.products.length">
                                <div class="grid gap-3">
                                    <template x-for="service in selectedVendor.products" :key="service.id">
                                        <button type="button"
                                            @click="selectService(service)"
                                            class="w-full text-left border-2 rounded-xl p-5 transition-all duration-200 group"
                                            :class="selectedService?.id === service.id 
                                                ? 'border-purple-500 bg-purple-50 shadow-lg shadow-purple-100' 
                                                : 'border-gray-100 hover:border-purple-200 hover:bg-gray-50'"
                                        >
                                            <div class="flex items-start justify-between gap-4">
                                                <div class="flex-1 min-w-0">
                                                    <div class="flex items-center gap-2">
                                                        <p class="text-base font-bold text-gray-900" x-text="service.name"></p>
                                                        <span x-show="selectedService?.id === service.id" class="px-2 py-0.5 bg-purple-600 text-white text-xs font-semibold rounded-full">Selected</span>
                                                    </div>
                                                    <p class="text-sm text-gray-500 mt-1 line-clamp-2" x-text="service.display_description || 'Tap to select this service'"></p>
                                                    <div class="flex flex-wrap gap-2 mt-3">
                                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-purple-100 text-purple-700 rounded-lg text-xs font-medium" x-show="service.metadata?.network">
                                                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path d="M2 11a1 1 0 011-1h2a1 1 0 011 1v5a1 1 0 01-1 1H3a1 1 0 01-1-1v-5zm6-4a1 1 0 011-1h2a1 1 0 011 1v9a1 1 0 01-1 1H9a1 1 0 01-1-1V7zm6-3a1 1 0 011-1h2a1 1 0 011 1v12a1 1 0 01-1 1h-2a1 1 0 01-1-1V4z"></path></svg>
                                                            <span x-text="service.metadata?.network"></span>
                                                        </span>
                                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-blue-100 text-blue-700 rounded-lg text-xs font-medium" x-show="service.metadata?.size">
                                                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd"></path></svg>
                                                            <span x-text="service.metadata?.size"></span>
                                                        </span>
                                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-green-100 text-green-700 rounded-lg text-xs font-medium" x-show="service.metadata?.validity">
                                                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"></path></svg>
                                                            <span x-text="service.metadata?.validity"></span>
                                                        </span>
                                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-amber-100 text-amber-700 rounded-lg text-xs font-medium" x-show="service.metadata?.tag">
                                                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M17.707 9.293a1 1 0 010 1.414l-7 7a1 1 0 01-1.414 0l-7-7A.997.997 0 012 10V5a3 3 0 013-3h5c.256 0 .512.098.707.293l7 7zM5 6a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"></path></svg>
                                                            <span x-text="service.metadata?.tag"></span>
                                                        </span>
                                                    </div>
                                                </div>
                                                <div class="text-right flex-shrink-0">
                                                    <p class="text-xl font-bold text-purple-600" x-text="formatCurrency(service.price)"></p>
                                                    <p class="text-xs text-gray-400 mt-1 group-hover:text-purple-500 transition-colors">Tap to select</p>
                                                </div>
                                            </div>
                                        </button>
                                    </template>
                                </div>
                            </template>
                            <div class="text-center py-8" x-show="!selectedVendor?.products.length" x-cloak>
                                <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                                    <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                                    </svg>
                                </div>
                                <p class="text-gray-500">This vendor has no active services yet.</p>
                                <p class="text-sm text-gray-400 mt-1">Please choose another vendor.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Contact Information Card -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="bg-gradient-to-r from-blue-50 to-cyan-50 px-6 py-4 border-b border-gray-100">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-white rounded-xl flex items-center justify-center shadow-sm">
                                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h2 class="text-lg font-semibold text-gray-900">Contact Information</h2>
                                    <p class="text-sm text-gray-500">Enter phone numbers for delivery and payment</p>
                                </div>
                            </div>
                        </div>
                        <div class="p-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="recipient_phone_number" class="block text-sm font-semibold text-gray-700 mb-2">
                                        Recipient Phone Number
                                    </label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                                            </svg>
                                        </div>
                                        <input 
                                            type="tel"
                                            id="recipient_phone_number"
                                            name="recipient_phone_number"
                                            placeholder="+233 XX XXX XXXX"
                                            required
                                            class="w-full pl-12 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-gray-900 placeholder-gray-400 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-all"
                                        >
                                    </div>
                                    <p class="mt-2 text-xs text-gray-500">The phone number that will receive the service</p>
                                </div>
                                
                                <div>
                                    <label for="mobile_money_number" class="block text-sm font-semibold text-gray-700 mb-2">
                                        Mobile Money Number
                                    </label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                            </svg>
                                        </div>
                                        <input 
                                            type="tel"
                                            id="mobile_money_number"
                                            name="mobile_money_number"
                                            placeholder="+233 XX XXX XXXX"
                                            required
                                            class="w-full pl-12 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-gray-900 placeholder-gray-400 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-all"
                                        >
                                    </div>
                                    <p class="mt-2 text-xs text-gray-500">Your mobile money number for payment</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Submit Button (Mobile) -->
                    <div class="lg:hidden">
                        <button 
                            type="submit" 
                            x-bind:disabled="processing || !selectedService"
                            class="w-full py-4 px-6 bg-gradient-to-r from-purple-600 to-indigo-600 text-white text-lg font-semibold rounded-2xl shadow-lg shadow-purple-200 hover:from-purple-700 hover:to-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition-all"
                        >
                            <span x-show="!processing" class="flex items-center justify-center gap-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                                Complete Payment
                            </span>
                            <span x-show="processing" class="flex items-center justify-center">
                                <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Processing...
                            </span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Sidebar - Order Summary -->
            <div class="lg:col-span-1">
                <div class="sticky top-8 space-y-6">
                    <!-- Order Summary Card -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="bg-gradient-to-r from-amber-50 to-orange-50 px-6 py-4 border-b border-gray-100">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-white rounded-xl flex items-center justify-center shadow-sm">
                                    <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                                    </svg>
                                </div>
                                <h2 class="text-lg font-semibold text-gray-900">Order Summary</h2>
                            </div>
                        </div>
                        <div class="p-6">
                            <!-- Selected Service Display -->
                            <div x-show="selectedService" x-cloak class="mb-6">
                                <div class="flex items-start gap-4 p-4 bg-purple-50 rounded-xl border border-purple-100">
                                    <!-- Network Image or Fallback Icon -->
                                    <template x-if="selectedService?.network_image_url">
                                        <img :src="selectedService.network_image_url" 
                                             :alt="selectedService?.metadata?.network || 'Service'" 
                                             class="w-12 h-12 rounded-xl object-cover flex-shrink-0 shadow-sm">
                                    </template>
                                    <template x-if="!selectedService?.network_image_url">
                                        <div class="w-12 h-12 bg-gradient-to-br from-purple-500 to-indigo-500 rounded-xl flex items-center justify-center flex-shrink-0">
                                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                            </svg>
                                        </div>
                                    </template>
                                    <div class="flex-1 min-w-0">
                                        <p class="font-semibold text-gray-900 truncate" x-text="selectedService?.name"></p>
                                        <p class="text-sm text-gray-500 mt-1" x-show="selectedService?.metadata?.network" x-text="selectedService?.metadata?.network"></p>
                                        <div class="flex flex-wrap gap-1 mt-2">
                                            <span class="text-xs px-2 py-0.5 bg-white rounded text-gray-600" x-show="selectedService?.metadata?.size" x-text="selectedService?.metadata?.size"></span>
                                            <span class="text-xs px-2 py-0.5 bg-white rounded text-gray-600" x-show="selectedService?.metadata?.validity" x-text="selectedService?.metadata?.validity"></span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- No Selection State -->
                            <div x-show="!selectedService" class="text-center py-6 mb-6">
                                <div class="w-14 h-14 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-3">
                                    <svg class="w-7 h-7 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                    </svg>
                                </div>
                                <p class="text-gray-500 text-sm">Select a service to continue</p>
                            </div>

                            <!-- Price Breakdown -->
                            <div class="space-y-3">
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-500">Service Amount</span>
                                    <span class="text-gray-900 font-medium" x-text="selectedService ? formatCurrency(selectedService.price) : 'GHS 0.00'"></span>
                                </div>
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-500">Processing Fee</span>
                                    <span class="text-green-600 font-medium">FREE</span>
                                </div>
                                <div class="border-t border-gray-200 pt-3 mt-3">
                                    <div class="flex justify-between items-center">
                                        <span class="text-base font-semibold text-gray-900">Total</span>
                                        <span class="text-2xl font-bold text-purple-600" x-text="selectedService ? formatCurrency(selectedService.price) : 'GHS 0.00'"></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Submit Button (Desktop) -->
                    <div class="hidden lg:block">
                        <button 
                            type="submit"
                            form="checkout-form"
                            onclick="document.querySelector('form').dispatchEvent(new Event('submit', {cancelable: true, bubbles: true}))"
                            x-bind:disabled="processing || !selectedService"
                            class="w-full py-4 px-6 bg-gradient-to-r from-purple-600 to-indigo-600 text-white text-lg font-semibold rounded-2xl shadow-lg shadow-purple-200 hover:from-purple-700 hover:to-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition-all"
                        >
                            <span x-show="!processing" class="flex items-center justify-center gap-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                                Complete Payment
                            </span>
                            <span x-show="processing" class="flex items-center justify-center">
                                <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Processing...
                            </span>
                        </button>
                        
                        <a href="{{ route('storefront.index') }}" 
                           class="mt-3 w-full py-3 px-6 bg-white text-gray-600 text-base font-medium rounded-xl border border-gray-200 hover:bg-gray-50 transition-all flex items-center justify-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                            </svg>
                            Cancel
                        </a>
                    </div>

                    <!-- Security Card -->
                    <div class="bg-gradient-to-br from-green-50 to-emerald-50 rounded-2xl p-5 border border-green-100">
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 bg-white rounded-xl flex items-center justify-center shadow-sm flex-shrink-0">
                                <svg class="w-5 h-5 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/>
                                </svg>
                            </div>
                            <div>
                                <h3 class="font-semibold text-green-900">Secure Payment</h3>
                                <p class="text-sm text-green-700 mt-1">
                                    Protected by end-to-end encryption and fraud protection.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Security Badges -->
                    <div class="flex items-center justify-center gap-4 py-4">
                        <div class="flex items-center gap-2 text-xs text-gray-400">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/>
                            </svg>
                            <span>SSL</span>
                        </div>
                        <div class="flex items-center gap-2 text-xs text-gray-400">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                            <span>256-bit</span>
                        </div>
                        <div class="flex items-center gap-2 text-xs text-gray-400">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                            <span>Verified</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

