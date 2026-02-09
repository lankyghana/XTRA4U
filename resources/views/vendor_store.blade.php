{{-- vendor_store.blade.php
     AJAX-driven vendor storefront
     - Category -> Service -> Package -> Checkout
     - Uses Alpine.js + fetch()
     - Replace API endpoints below with your real routes
--}}

@extends('layouts.app') {{-- adjust to your layout --}}

@section('content')
<script>
    window.vendorStoreData = {
        vendorId: {{ $vendor->id ?? 'null' }},
        categories: {!! json_encode($categories ?? []) !!},
        services: {!! json_encode($services ?? []) !!},
        requiresInlineMomo: {{ ($requiresInlineMomo ?? false) ? 'true' : 'false' }},
        initialPaymentFailed: {{ session('payment_failed') ? 'true' : 'false' }},
        initialPaymentFailureMessage: {!! json_encode(session('payment_message') ?? null) !!},
        orderRoute: '{{ route('checkout.process') }}',
        verifyRoute: '{{ route('checkout.verify') }}'
    };
</script>
<div class="w-full max-w-full md:max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-24 md:pb-0"
     x-data="typeof vendorStore === 'function' ? vendorStore(window.vendorStoreData) : {}"
     x-init="typeof init === 'function' && init()">

    {{-- Vendor Storefront Header --}}
    <div class="text-center my-8">
        <p class="text-sm text-gray-500 uppercase tracking-wide mb-2">Vendor Storefront</p>
        <h1 class="text-3xl md:text-4xl font-bold text-gray-900 mb-4">Shop with {{ $vendor->name }}</h1>
        <div class="inline-flex items-center px-4 py-2 bg-purple-100 rounded-full">
            <span class="text-sm font-medium text-purple-700">Agent: {{ $vendor->name }} ({{ $vendor->vendor_code ?? 'N/A' }})</span>
        </div>
    </div>

    {{-- Order Status Tracker - Collapsible --}}
    <div x-data="orderTracker()" class="mb-6">
        {{-- Toggle Button --}}
        <button 
            @click="isOpen = !isOpen" 
            class="w-full flex items-center justify-between px-4 py-3 bg-gradient-to-r from-purple-50 to-blue-50 border border-purple-100 rounded-xl hover:from-purple-100 hover:to-blue-100 transition-all duration-200"
        >
            <div class="flex items-center">
                <div class="w-8 h-8 bg-purple-600 rounded-lg flex items-center justify-center mr-3">
                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                    </svg>
                </div>
                <span class="font-medium text-gray-700">Track Your Order</span>
            </div>
            <svg class="w-5 h-5 text-gray-500 transition-transform duration-200" :class="isOpen ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>

        {{-- Expandable Content --}}
        <div 
            x-show="isOpen" 
            x-collapse
            x-cloak
            class="mt-2 bg-white border border-gray-100 rounded-xl shadow-sm overflow-hidden"
        >
            <div class="p-4">
                {{-- Search Form --}}
                <form @submit.prevent="checkStatus" class="flex gap-2">
                    <div class="flex-1 relative">
                        <label for="order-track-phone" class="sr-only">Enter recipient phone number</label>
                        <input 
                            type="tel" 
                            id="order-track-phone"
                            name="order_track_phone"
                            x-model="phone"
                            placeholder="Enter recipient phone number"
                            autocomplete="tel"
                            class="w-full pl-10 pr-4 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-sm"
                        >
                        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                        </svg>
                    </div>
                    <button 
                        type="submit" 
                        :disabled="loading"
                        class="px-4 py-2.5 bg-purple-600 text-white text-sm font-medium rounded-lg hover:bg-purple-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors flex items-center"
                    >
                        <template x-if="loading">
                            <svg class="animate-spin w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </template>
                        <span x-text="loading ? 'Checking...' : 'Check'"></span>
                    </button>
                </form>

                {{-- Results --}}
                <div class="mt-3" x-show="searched" x-cloak>
                    {{-- Error/No results --}}
                    <template x-if="error || (orders.length === 0 && searched)">
                        <div class="text-sm text-gray-500 text-center py-3">
                            <span x-text="error || 'No orders found for this number.'"></span>
                        </div>
                    </template>

                    {{-- Orders List --}}
                    <template x-if="orders.length > 0">
                        <div class="space-y-2 max-h-64 overflow-y-auto">
                            <template x-for="order in orders" :key="order.id">
                                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2">
                                            <span class="text-sm font-medium text-gray-900 truncate" x-text="order.service"></span>
                                            <span class="text-xs text-gray-500">•</span>
                                            <span class="text-xs text-gray-500" x-text="'GH₵' + order.amount"></span>
                                        </div>
                                        <div class="text-xs text-gray-500 mt-0.5">
                                            <span x-text="order.date"></span> • <span x-text="order.time"></span>
                                        </div>
                                    </div>
                                    <div class="flex-shrink-0 ml-3">
                                        <span 
                                            class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium"
                                            :class="order.status_color.bg + ' ' + order.status_color.text"
                                        >
                                            <span class="w-1.5 h-1.5 rounded-full mr-1.5" :class="order.status_color.dot"></span>
                                            <span x-text="order.status_label"></span>
                                        </span>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>

    {{-- Header text --}}
    <div class="text-center my-8 text-gray-600">
        <p>Explore network bundles, electricity tokens, online vouchers, and more.</p>
    </div>

    {{-- CATEGORY SELECTOR: full width card (hidden after package selection) --}}
    <div class="bg-white rounded-xl shadow-md p-6 mb-6" x-show="!isCheckoutOnlyMode" x-cloak>
        <h3 class="text-xs tracking-wider text-gray-400 uppercase">CATEGORY SELECTOR</h3>
        <h2 class="text-2xl font-semibold mt-2">Choose a category</h2>

        <div class="mt-4">
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-6 mt-3">
                @if(count($categories))
                    @foreach($categories as $index => $category)
                        <button type="button"
                            class="flex flex-col items-center justify-center p-6 rounded-2xl border text-lg shadow-md transition-all duration-200 hover:shadow-xl hover:-translate-y-0.5 min-h-[110px]"
                            :class="selectedCategory && selectedCategory.value === '{{ $category['value'] }}' ? 'bg-purple-100 border-purple-400 text-purple-700 font-semibold ring-2 ring-purple-300' : 'bg-white border-gray-200 text-gray-700 hover:bg-purple-50 hover:border-purple-200'"
                            @click="selectCategory({{ $index }})">
                            <span class="text-center font-medium">{{ $category['label'] }}</span>
                        </button>
                    @endforeach
                @else
                    <div class="col-span-full text-sm text-gray-500">No categories available. Please contact the vendor.</div>
                @endif
            </div>
        </div>
    </div>

    {{-- 3-column area (columns revealed progressively) --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

        {{-- 1) CHOOSE SERVICE (revealed after selecting a category) --}}
        <div class="bg-white rounded-xl shadow-md p-6" x-show="step >= 2 && !isCheckoutOnlyMode" x-cloak x-transition>
            <h3 class="text-xl font-semibold mb-4">Choose Service</h3>

            <template x-if="loadingServices">
                <div class="text-gray-500">Loading services…</div>
            </template>

            <template x-if="!loadingServices && filteredServices.length">
                <div class="space-y-3">
                    <template x-for="svc in filteredServices" :key="svc.key">
                        <div class="p-3 rounded-lg border shadow-sm cursor-pointer transition hover:shadow-md"
                            :class="selectedService && selectedService.key === svc.key ? 'bg-purple-50 border-purple-400' : 'bg-white border-gray-100'"
                            @click="selectService(svc)">
                            <div class="flex items-center gap-3">
                                <img :src="svc.logo || '/images/default-provider.png'" alt="" class="w-10 h-10 rounded-md object-cover">
                                <div>
                                    <div class="font-medium" x-text="svc.name"></div>
                                    <div class="text-sm text-gray-500" x-text="svc.package_count ? svc.package_count + ' packages' : ''"></div>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </template>

            <template x-if="!loadingServices && !filteredServices.length">
                <div class="text-sm text-gray-500">No services available for this category.</div>
            </template>
        </div>

        {{-- 2) SELECT PACKAGE / SELECTED PACKAGE (list hidden after selection) --}}
        <div id="package-section" x-ref="packageSection" x-show="step >= 3" x-cloak x-transition class="bg-white rounded-xl shadow-md p-6">
            <template x-if="!isCheckoutOnlyMode">
                <div>
                    <h3 class="text-xl font-semibold mb-4">Select Package</h3>

                    <template x-if="loadingPackages">
                        <div class="text-gray-500">Loading packages…</div>
                    </template>

                    <template x-if="!loadingPackages && availablePackages.length">
                        <div class="space-y-4">
                            <template x-for="(pkg, idx) in availablePackages" :key="String(pkg.id) + '_' + idx">
                                <div class="p-4 rounded-xl border shadow-sm hover:shadow-md transition cursor-pointer"
                                    :class="selectedPackage && selectedPackage.id === pkg.id ? 'border-purple-400 bg-purple-50' : 'border-gray-100 bg-white'"
                                    @click="selectPackage(pkg)">

                            <div class="flex items-start gap-4">
                                <!-- Service Logo -->
                                <img :src="selectedService?.logo || '/images/default-provider.png'" 
                                     alt="" 
                                     class="w-12 h-12 rounded-md object-cover flex-shrink-0">

                                <!-- Package Details -->
                                <div class="flex-1 flex justify-between items-start">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2">
                                            <div class="font-semibold" x-text="pkg.name"></div>
                                            <span x-show="pkg.tag" x-text="pkg.tag" class="px-2 py-0.5 text-xs font-medium bg-green-100 text-green-700 rounded-full"></span>
                                        </div>
                                        <div class="text-sm text-gray-500 mt-1" x-text="pkg.size || ''"></div>
                                    </div>

                                    <div class="text-right ml-4">
                                        <div class="text-lg font-bold text-purple-600" x-text="formatCurrency(pkg.price)"></div>
                                        <div class="text-sm text-green-600 mt-1" x-text="pkg.validity || ''"></div>
                                    </div>
                                </div>
                            </div>

                                </div>
                            </template>
                        </div>
                    </template>

                    <template x-if="!loadingPackages && !availablePackages.length">
                        <div class="text-sm text-gray-500">No packages available for this service.</div>
                    </template>
                </div>
            </template>

            <template x-if="isCheckoutOnlyMode">
                <div>
                    <div class="flex items-center justify-between gap-3 mb-4">
                        <h3 class="text-xl font-semibold">Selected Package</h3>
                        <button type="button"
                            class="text-sm font-medium text-purple-700 hover:text-purple-800"
                            @click="expandPackages()">
                            Change package
                        </button>
                    </div>

                    <div class="p-4 rounded-xl border border-purple-400 bg-purple-50 shadow-sm">
                        <div class="flex items-start gap-4">
                            <img :src="selectedService?.logo || '/images/default-provider.png'"
                                 alt=""
                                 class="w-12 h-12 rounded-md object-cover flex-shrink-0">

                            <div class="flex-1 flex justify-between items-start">
                                <div class="flex-1">
                                    <div class="flex items-center gap-2">
                                        <div class="font-semibold" x-text="selectedPackage?.name"></div>
                                        <span x-show="selectedPackage?.tag" x-text="selectedPackage?.tag" class="px-2 py-0.5 text-xs font-medium bg-green-100 text-green-700 rounded-full"></span>
                                    </div>
                                    <div class="text-sm text-gray-500 mt-1" x-text="selectedPackage?.size || ''"></div>
                                </div>

                                <div class="text-right ml-4">
                                    <div class="text-lg font-bold text-purple-600" x-text="formatCurrency(selectedPackage?.price)"></div>
                                    <div class="text-sm text-green-600 mt-1" x-text="selectedPackage?.validity || ''"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        {{-- 3) CHECKOUT (reveal after package) --}}
        <div id="checkout-section" x-ref="checkoutSection" x-show="step >= 4" x-cloak x-transition class="bg-white rounded-xl shadow-md p-6 md:sticky md:top-20 self-start">
            <h3 class="text-xl font-semibold mb-4">Checkout</h3>

            <div class="bg-gray-50 p-4 rounded-lg mb-4">
                <div class="flex justify-between text-sm text-gray-600">
                    <div>Category:</div><div class="font-medium" x-text="selectedCategory?.label"></div>
                </div>
                <div class="flex justify-between text-sm text-gray-600 mt-2">
                    <div>Network:</div><div class="font-medium" x-text="selectedService?.name"></div>
                </div>
                <div class="flex justify-between text-sm text-gray-600 mt-2">
                    <div>Package:</div><div class="font-medium" x-text="selectedPackage?.size || selectedPackage?.name || selectedPackage?.title"></div>
                </div>
                <div class="flex justify-between text-lg text-purple-700 font-bold mt-4">
                    <div>Price:</div><div x-text="formatCurrency(selectedPackage?.price)"></div>
                </div>
            </div>

            {{-- Checkout form (example: posts to order route) --}}
            <form :action="orderRoute" method="POST" @submit.prevent="submitOrder">
                @csrf
                <input type="hidden" name="vendor_id" :value="vendorId">
                <input type="hidden" name="category_id" :value="selectedCategory?.id">
                <input type="hidden" name="service_id" :value="selectedService?.key">
                <input type="hidden" name="package_id" :value="selectedPackage?.id">
                <input type="hidden" name="amount" :value="selectedPackage?.price">
                <input type="hidden" name="is_reseller_product" :value="selectedPackage?.is_reseller_product ? 1 : 0">
                <input type="hidden" name="reseller_product_id" :value="selectedPackage?.reseller_product_id">
                <input type="hidden" name="original_product_id" :value="selectedPackage?.original_product_id">

                {{-- minimal checkout fields (recipient phone) --}}
                <div class="mb-3">
                    <label for="recipient_phone" class="block text-sm text-gray-600 mb-1">Recipient phone</label>
                    <input type="tel" id="recipient_phone" name="recipient_phone" x-model="recipientPhone" required
                        x-ref="recipientPhoneInput"
                        autocomplete="tel"
                        placeholder="e.g. 0244123456"
                        class="w-full border border-gray-200 rounded-lg p-3 focus:ring-2 focus:ring-purple-300">
                </div>

                <button type="submit"
                    class="w-full bg-purple-600 hover:bg-purple-700 text-white rounded-xl py-3 font-semibold disabled:opacity-60"
                    :disabled="submitting || paymentPolling || paymentFailed">
                    <span x-show="!submitting && !paymentPolling && !paymentFailed">Proceed to Payment</span>
                    <span x-show="submitting">Processing…</span>
                    <span x-show="paymentPolling">Waiting for verification…</span>
                    <span x-show="paymentFailed">Payment failed</span>
                </button>
            </form>

            {{-- Inline MoMo Modal (for non-redirect gateways like BulkClix) --}}
            <div x-show="momoModalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center">
                <div class="absolute inset-0 bg-black/50" @click="cancelMomoDetails"></div>
                <div class="relative bg-white rounded-xl shadow-md w-full max-w-md p-6 mx-4">
                    <h4 class="text-lg font-semibold mb-4">Mobile Money Payment</h4>
                    <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4">
                        <div class="flex items-start gap-3">
                            <svg class="w-5 h-5 text-amber-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M12 2a10 10 0 100 20 10 10 0 000-20z"/>
                            </svg>
                            <div class="text-sm text-amber-900">
                                <p class="font-semibold">Didn’t receive the MoMo prompt?</p>
                                <p class="mt-1 text-amber-800">
                                    You can approve the payment manually from your MoMo menu:
                                </p>
                                <ol class="mt-2 list-decimal pl-5 space-y-1 text-amber-800">
                                    <li>Dial <span class="font-semibold">*170#</span></li>
                                    <li>Select <span class="font-semibold">6</span> (My Wallet)</li>
                                    <li>Select <span class="font-semibold">3</span> (My Approvals)</li>
                                    <li>Approve the pending transaction</li>
                                </ol>
                                <p class="mt-2 text-xs text-amber-700">
                                    Tip: Make sure your phone has network signal and you have enough balance to cover any MoMo charges.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="payer_phone" class="block text-sm text-gray-600 mb-1">MoMo number</label>
                        <input type="tel"
                               id="payer_phone"
                               name="payer_phone"
                               x-model="payerPhone"
                               inputmode="tel"
                               autocomplete="tel"
                               class="w-full border border-gray-200 rounded-lg p-3 focus:ring-2 focus:ring-purple-300"
                               placeholder="e.g. 0551234567">
                    </div>

                    <div class="mb-4">
                        <label for="payer_network" class="block text-sm text-gray-600 mb-1">Network</label>
                        <select x-model="payerNetwork"
                                id="payer_network"
                                name="payer_network"
                                class="w-full border border-gray-200 rounded-lg p-3 focus:ring-2 focus:ring-purple-300">
                            <option value="">Select network</option>
                            <option value="MTN">MTN</option>
                            <option value="TELECEL">Telecel</option>
                            <option value="AIRTELTIGO">AirtelTigo</option>
                        </select>
                    </div>

                    <div class="flex gap-3">
                        <button type="button"
                                class="flex-1 border border-gray-200 rounded-xl py-3 font-semibold text-gray-700 hover:bg-gray-50"
                                @click="cancelMomoDetails">
                            Cancel
                        </button>
                        <button type="button"
                                class="flex-1 bg-purple-600 hover:bg-purple-700 text-white rounded-xl py-3 font-semibold"
                                @click="confirmMomoDetails">
                            Confirm & Pay
                        </button>
                    </div>

                    <p class="text-xs text-gray-500 mt-3">Enter the number that will approve the payment prompt.</p>
                </div>
            </div>

            <div class="mt-4 text-sm text-gray-500" x-show="orderMessage" x-text="orderMessage"></div>
        </div>

        {{-- Full-screen verification overlay (inline gateways) --}}
        <div x-show="paymentPolling" x-cloak class="fixed inset-0 z-50 flex items-center justify-center">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm"></div>
            <div class="relative w-full max-w-lg mx-4 rounded-2xl bg-white shadow-2xl overflow-hidden">
                <div class="p-6 sm:p-8">
                    <div class="flex items-center gap-4">
                        <div class="relative flex h-14 w-14 items-center justify-center rounded-2xl bg-purple-50">
                            <svg class="h-8 w-8 text-purple-600" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M12 2a10 10 0 100 20 10 10 0 000-20z" stroke="currentColor" stroke-width="2" opacity="0.2" />
                                <path d="M12 6v6l4 2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            <svg class="absolute -inset-1 h-16 w-16 animate-spin text-purple-600" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v3a5 5 0 00-5 5H4z"></path>
                            </svg>
                        </div>

                        <div class="flex-1">
                            <h3 class="text-xl font-semibold text-gray-900">Verifying your payment</h3>
                            <p class="mt-1 text-sm text-gray-600">We’re confirming your Mobile Money transaction. This usually takes a moment.</p>
                        </div>
                    </div>

                    <div class="mt-6 rounded-xl border border-gray-100 bg-gray-50 p-4">
                        <div class="flex items-start gap-3">
                            <div class="mt-0.5 h-7 w-7 rounded-full bg-purple-100 flex items-center justify-center">
                                <svg class="h-4 w-4 text-purple-700" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8.75 3.5a.75.75 0 001.5 0V9a.75.75 0 00-1.5 0v4.5zm.75-7.25a1 1 0 100 2 1 1 0 000-2z" clip-rule="evenodd" />
                                </svg>
                            </div>
                            <div class="text-sm text-gray-700">
                                <div class="font-medium">Don’t close or refresh this page</div>
                                <div class="mt-0.5 text-gray-600">If you received a MoMo prompt, please approve it to complete payment.</div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-6">
                        <div class="h-2 w-full rounded-full bg-gray-100 overflow-hidden">
                            <div class="h-2 w-1/3 rounded-full bg-purple-600 animate-pulse"></div>
                        </div>
                        <p class="mt-3 text-xs text-gray-500">You’ll be redirected automatically once payment is confirmed.</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Full-screen failure overlay --}}
        <div x-show="paymentFailed" x-cloak class="fixed inset-0 z-50 flex items-center justify-center">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm"></div>
            <div class="relative w-full max-w-lg mx-4 rounded-2xl bg-white shadow-2xl overflow-hidden">
                <div class="p-6 sm:p-8">
                    <div class="flex items-center gap-4">
                        <div class="relative flex h-14 w-14 items-center justify-center rounded-2xl bg-red-50">
                            <svg class="h-8 w-8 text-red-600" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M12 2a10 10 0 100 20 10 10 0 000-20z" stroke="currentColor" stroke-width="2" opacity="0.2" />
                                <path d="M15 9l-6 6M9 9l6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </div>

                        <div class="flex-1">
                            <h3 class="text-xl font-semibold text-gray-900">Payment failed</h3>
                            <p class="mt-1 text-sm text-gray-600" x-text="paymentFailureMessage || 'Your payment was not completed. Please try again.'"></p>
                        </div>
                    </div>

                    <div class="mt-6 rounded-xl border border-red-100 bg-red-50 p-4">
                        <div class="text-sm text-red-800">
                            You will be returned to checkout so you can try again.
                        </div>
                    </div>

                    <div class="mt-6 flex gap-3">
                        <button type="button"
                                class="flex-1 bg-purple-600 hover:bg-purple-700 text-white rounded-xl py-3 font-semibold"
                                @click="dismissPaymentFailure">
                            Back to Checkout
                        </button>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>


{{-- WhatsApp Contact Section --}}
@if($vendor->phone_number)
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 my-12">
    <div class="bg-gradient-to-r from-green-50 to-green-100 rounded-2xl p-8 text-center shadow-lg">
        <h2 class="text-2xl font-bold text-gray-900 mb-4">Need Help?</h2>
        <p class="text-gray-600 mb-6">Contact {{ $vendor->name }} if your order takes longer than 2 hours.</p>
        <a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $vendor->phone_number) }}" 
           target="_blank"
           class="inline-flex items-center gap-2 px-8 py-4 bg-green-500 hover:bg-green-600 text-white font-semibold rounded-xl transition-all duration-200 shadow-md hover:shadow-xl transform hover:scale-105">
            <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/>
            </svg>
            Contact vendor: {{ $vendor->phone_number }}
        </a>
    </div>
</div>
@endif

@endsection

@push('scripts')
<script>
function orderTracker() {
    return {
        isOpen: false,
        phone: '',
        orders: [],
        loading: false,
        error: null,
        searched: false,
        pollingInterval: null,

        async checkStatus() {
            if (!this.phone || this.phone.length < 10) {
                this.error = 'Please enter a valid phone number';
                return;
            }

            this.loading = true;
            this.error = null;
            this.orders = [];

            try {
                const response = await fetch('{{ route("order.status.check") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ phone: this.phone, limit: 3 })
                });

                const data = await response.json();

                if (data.success) {
                    this.orders = data.orders;
                    this.startPolling();
                } else {
                    this.error = data.message || 'No orders found.';
                }
            } catch (err) {
                console.error(err);
                this.error = 'An error occurred. Please try again.';
            } finally {
                this.loading = false;
                this.searched = true;
            }
        },

        startPolling() {
            if (this.pollingInterval) clearInterval(this.pollingInterval);
            
            this.pollingInterval = setInterval(async () => {
                if (this.orders.length === 0) {
                    clearInterval(this.pollingInterval);
                    return;
                }

                try {
                    const orderIds = this.orders.map(o => o.id);
                    const response = await fetch('{{ route("order.status.poll") }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({ order_ids: orderIds })
                    });

                    const data = await response.json();
                    data.orders.forEach(updated => {
                        const order = this.orders.find(o => o.id === updated.id);
                        if (order && order.status !== updated.status) {
                            order.status = updated.status;
                            order.status_label = updated.status_label;
                            order.status_color = updated.status_color;
                            order.updated_at = updated.updated_at;
                        }
                    });
                } catch (err) {
                    console.log('Polling error:', err);
                }
            }, 30000);
        }
    };
}
</script>
@endpush