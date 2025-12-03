{{-- vendor_store.blade.php
     AJAX-driven vendor storefront
     - Category -> Service -> Package -> Checkout
     - Uses Alpine.js + fetch()
     - Replace API endpoints below with your real routes
--}}

@extends('layouts.app') {{-- adjust to your layout --}}

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8" x-data='vendorStore({
        vendorId: {{ $vendor->id ?? 'null' }},
        categories: @json($categories ?? []),
        services: @json($services ?? []),
        orderRoute: "{{ route('checkout.process') }}"
    })'
>

    {{-- Vendor Storefront Header --}}
    <div class="text-center my-8">
        <p class="text-sm text-gray-500 uppercase tracking-wide mb-2">Vendor Storefront</p>
        <h1 class="text-3xl md:text-4xl font-bold text-gray-900 mb-4">Shop with {{ $vendor->name }}</h1>
        <div class="inline-flex items-center px-4 py-2 bg-purple-100 rounded-full">
            <span class="text-sm font-medium text-purple-700">Agent: {{ $vendor->name }} ({{ $vendor->vendor_code ?? 'N/A' }})</span>
        </div>
    </div>

    {{-- Header text --}}
    <div class="text-center my-8 text-gray-600">
        <p>Explore network bundles, electricity tokens, online vouchers, and more — pick a category to start.</p>
    </div>

    {{-- CATEGORY SELECTOR: full width card (visible immediately) --}}
    <div class="bg-white rounded-xl shadow-md p-6 mb-6">
        <h3 class="text-xs tracking-wider text-gray-400">CATEGORY SELECTOR</h3>
        <h2 class="text-2xl font-semibold mt-2">Choose a category</h2>

        <div class="mt-4">
            <div class="flex flex-wrap gap-3 mt-3">
                @forelse($categories as $category)
                    <button type="button"
                        class="px-4 py-2 rounded-full border text-sm shadow-sm transition"
                        :class="selectedCategory && selectedCategory.value === '{{ $category['value'] }}' ? 'bg-purple-100 border-purple-400 text-purple-700 font-medium' : 'bg-white border-gray-200 text-gray-700 hover:bg-purple-50'"
                        @click='selectCategory(@json($category))'>
                        {{ $category['label'] }}
                    </button>
                @empty
                    <div class="text-sm text-gray-500">No categories available. Please contact the vendor.</div>
                @endforelse
            </div>
        </div>
    </div>

    {{-- 3-column area (columns revealed progressively) --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

        {{-- 1) CHOOSE SERVICE (reveal after category) --}}
        <div x-show="step >= 2" x-cloak x-transition class="bg-white rounded-xl shadow-md p-6">
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

        {{-- 2) SELECT PACKAGE (reveal after service) --}}
        <div x-show="step >= 3" x-cloak x-transition class="bg-white rounded-xl shadow-md p-6">
            <h3 class="text-xl font-semibold mb-4">Select Package</h3>

            <template x-if="loadingPackages">
                <div class="text-gray-500">Loading packages…</div>
            </template>

            <template x-if="!loadingPackages && availablePackages.length">
                <div class="space-y-4">
                    <template x-for="pkg in availablePackages" :key="pkg.id">
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
                                    <div>
                                        <div class="font-semibold" x-text="pkg.title"></div>
                                        <div class="text-sm text-gray-500 mt-1" x-text="pkg.meta || pkg.description || ''"></div>
                                    </div>

                                    <div class="text-right">
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

        {{-- 3) CHECKOUT (reveal after package) --}}
        <div x-show="step >= 4" x-cloak x-transition class="bg-white rounded-xl shadow-md p-6">
            <h3 class="text-xl font-semibold mb-4">Checkout</h3>

            <div class="bg-gray-50 p-4 rounded-lg mb-4">
                <div class="flex justify-between text-sm text-gray-600">
                    <div>Category:</div><div class="font-medium" x-text="selectedCategory?.label"></div>
                </div>
                <div class="flex justify-between text-sm text-gray-600 mt-2">
                    <div>Network:</div><div class="font-medium" x-text="selectedService?.name"></div>
                </div>
                <div class="flex justify-between text-sm text-gray-600 mt-2">
                    <div>Package:</div><div class="font-medium" x-text="selectedPackage?.title"></div>
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

                {{-- minimal checkout fields (recipient phone & payer mobile money) --}}
                <div class="mb-3">
                    <label class="block text-sm text-gray-600 mb-1">Recipient phone</label>
                    <input type="tel" name="recipient_phone" x-model="recipientPhone" required
                        class="w-full border border-gray-200 rounded-lg p-3 focus:ring-2 focus:ring-purple-300">
                </div>

                <div class="mb-4">
                    <label class="block text-sm text-gray-600 mb-1">Mobile money (payer)</label>
                    <input type="tel" name="payer_phone" x-model="payerPhone" required
                        class="w-full border border-gray-200 rounded-lg p-3 focus:ring-2 focus:ring-purple-300">
                </div>

                <button type="submit"
                    class="w-full bg-purple-600 hover:bg-purple-700 text-white rounded-xl py-3 font-semibold disabled:opacity-60"
                    :disabled="submitting">
                    <span x-show="!submitting">Proceed to Payment</span>
                    <span x-show="submitting">Processing…</span>
                </button>
            </form>

            <div class="mt-4 text-sm text-gray-500" x-show="orderMessage" x-text="orderMessage"></div>
        </div>

    </div>
</div>

<script>
function vendorStore(opts = {}) {
    return {
        vendorId: opts.vendorId || null,
        categories: opts.categories || [],
        services: opts.services || [],
        selectedCategory: null,
        selectedService: null,
        selectedPackage: null,
        step: 1, // 1 = category only; 2 = service; 3 = package; 4 = checkout
        submitting: false,
        orderMessage: '',
        recipientPhone: '',
        payerPhone: '',
        loadingServices: false,
        loadingPackages: false,
        orderRoute: opts.orderRoute || '',

        init() {
            this.selectedCategory = null;
            const firstAvailable = this.categories.find((cat) =>
                this.services.some((service) => service.category === cat.value)
            );

            if (firstAvailable) {
                this.selectCategory(firstAvailable);
            }
        },

        get filteredServices() {
            if (!this.selectedCategory) {
                return [];
            }

            return this.services.filter((service) => service.category === this.selectedCategory.value);
        },

        get availablePackages() {
            return this.selectedService?.packages || [];
        },

        selectCategory(cat) {
            if (!cat) return;
            this.selectedCategory = cat;
            this.selectedService = null;
            this.selectedPackage = null;
            this.step = 2;
        },

        selectService(svc) {
            if (!svc) return;
            this.selectedService = svc;
            this.selectedPackage = null;
            this.step = 3;
        },

        selectPackage(pkg) {
            if (!pkg) return;
            this.selectedPackage = pkg;
            this.step = 4;
        },

        formatCurrency(v) {
            if (v === null || typeof v === 'undefined') return '';
            try {
                return new Intl.NumberFormat('en-GH', { style: 'currency', currency: 'GHS' }).format(v);
            } catch (error) {
                return 'GHS' + Number(v).toFixed(2);
            }
        },

        async submitOrder() {
            if (!this.selectedPackage) {
                this.orderMessage = 'Please select a package first.';
                return;
            }

            this.submitting = true;
            this.orderMessage = '';

            const payload = {
                vendor_id: this.vendorId,
                category_id: this.selectedCategory?.value,
                service_id: this.selectedService?.key,
                package_id: this.selectedPackage?.id,
                amount: this.selectedPackage?.price,
                recipient_phone: this.recipientPhone,
                payer_phone: this.payerPhone,
            };

            try {
                const res = await fetch(this.orderRoute, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify(payload)
                });

                if (!res.ok) {
                    const text = await res.text();
                    throw new Error(text || 'Order submission failed');
                }

                const resp = await res.json();
                if (resp.success) {
                    if (resp.redirect) {
                        window.location.href = resp.redirect;
                        return;
                    }
                    this.orderMessage = resp.message || 'Order submitted successfully';
                } else {
                    this.orderMessage = resp.message || 'Order failed';
                }
            } catch (err) {
                console.error(err);
                this.orderMessage = err.message || 'An error occurred while submitting the order';
            } finally {
                this.submitting = false;
            }
        }
    };
}
</script>
@endsection