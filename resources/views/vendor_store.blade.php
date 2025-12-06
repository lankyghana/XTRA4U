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
        orderRoute: "{{ route('checkout.process') }}",
        customerEmail: '',
        recipientPhone: '',
        payerPhone: '',
        submitting: false,
        orderMessage: '',
        selectedCategory: null,
        selectedService: null,
        selectedPackage: null,
        step: 1,
        loadingServices: false,
        filteredServices: [],
        loadingPackages: false,
        availablePackages: [],
        formatCurrency: value => {
            if (typeof value !== 'number') return '';
            return '₵' + value.toFixed(2);
        }
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
        <p>Explore network bundles, electricity tokens, online vouchers, and more.</p>
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
                <input type="hidden" name="is_reseller_product" :value="selectedPackage?.is_reseller_product ? 1 : 0">
                <input type="hidden" name="reseller_product_id" :value="selectedPackage?.reseller_product_id">
                <input type="hidden" name="original_product_id" :value="selectedPackage?.original_product_id">

                {{-- minimal checkout fields (recipient phone & payer mobile money) --}}
                    <div class="mb-3">
                        <label class="block text-sm text-gray-600 mb-1">Customer Email <span class="text-red-500">*</span></label>
                        <input type="email" name="customer_email" x-model="customerEmail" required
                            class="w-full border border-gray-200 rounded-lg p-3 focus:ring-2 focus:ring-purple-300">
                    </div>
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
        customerEmail: opts.customerEmail || '',
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
                customer_email: this.customerEmail,
                is_reseller_product: this.selectedPackage?.is_reseller_product ? 1 : 0,
                reseller_product_id: this.selectedPackage?.reseller_product_id || null,
                original_product_id: this.selectedPackage?.original_product_id || this.selectedPackage?.id,
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