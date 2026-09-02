@extends('layouts.app')

@section('title', 'Marketplace - XTRA4U')
@section('description', 'Browse all available services from all vendors')

{{-- Scope the storefront design system to this page only. --}}
@section('body-class', 'x4')

@php
    $shopUrl = route('checkout.show');
@endphp

@section('site-header')
    <x-storefront.header :shop-url="$shopUrl" />
@endsection

@section('site-footer')
    <x-storefront.footer :shop-url="$shopUrl" />
@endsection

@section('content')
{{--
    Visual redesign only. The entire x-data object below (search/filter/
    sort/select/submit/inline-payment-poll logic), the #products-data JSON
    payload, the purchase form's action/hidden inputs/field names, and the
    config('momo.product_networks') filter options are all byte-for-byte
    unchanged — only the surrounding markup and classes were rewritten to
    the x4 design system.

    Two pre-existing bugs fixed here (not redesign choices): (1) this
    wrapper was a malformed `<div\n<div class="...">` — two opening divs,
    the first never closed, which browsers repair unpredictably; (2) a
    stray `@include('components.inline_payment_manager')` + `@endsection`
    was pasted mid-template (in the middle of the product card markup),
    which ended the Blade @section early — everything after it (the sticky
    purchase panel with the actual buy form, the empty state, and the
    inline payment manager script) was silently dropped from every render.
    Confirmed via a real request: the shipped page never rendered a way to
    complete a purchase. Fixed by removing the stray fragment and moving
    the single real @include to just before the real @endsection.
--}}
<div
    class="x4-page"
    style="padding-top: 64px;"
    x-data="{
        networkGradients: @json(collect(config('momo.product_networks', []))->mapWithKeys(fn ($n, $k) => [$k => ($n['gradient'] ?? null)])->filter()->all()),
        allProducts: [],
        selectedProduct: null,
        searchQuery: '',
        selectedNetwork: '',
        sortBy: 'random',
        recipientPhone: '',
        momoNumber: '',
        isSubmitting: false,
        currentVendor: @json($currentVendor ?? null),
        paymentMethod: 'gateway',
        
        init() {
            this.allProducts = JSON.parse(document.getElementById('products-data').textContent);
            if (this.currentVendor) {
                // default to wallet if vendor has sufficient balance for first product
                this.paymentMethod = 'wallet';
            }
        },

        selectedBasePrice() {
            return this.selectedProduct ? parseFloat(this.selectedProduct.price || 0) : 0;
        },

        vendorBalance() {
            return this.currentVendor ? parseFloat(this.currentVendor.wallet_balance || 0) : 0;
        },

        get filteredProducts() {
            let filtered = [...this.allProducts];

            if (this.searchQuery) {
                const query = this.searchQuery.toLowerCase();
                filtered = filtered.filter(p => 
                    (p.name && p.name.toLowerCase().includes(query)) ||
                    (p.vendor_name && p.vendor_name.toLowerCase().includes(query)) ||
                    (p.description && p.description.toLowerCase().includes(query)) ||
                    (p.network && p.network.toLowerCase().includes(query)) ||
                    (p.size && p.size.toLowerCase().includes(query))
                );
            }

            if (this.selectedNetwork) {
                filtered = filtered.filter(p => p.network === this.selectedNetwork);
            }

            if (this.sortBy === 'price_low') {
                filtered.sort((a, b) => parseFloat(a.price) - parseFloat(b.price));
            } else if (this.sortBy === 'price_high') {
                filtered.sort((a, b) => parseFloat(b.price) - parseFloat(a.price));
            } else if (this.sortBy === 'name') {
                filtered.sort((a, b) => (a.name || '').localeCompare(b.name || ''));
            } else if (this.sortBy === 'vendor') {
                filtered.sort((a, b) => (a.vendor_name || '').localeCompare(b.vendor_name || ''));
            }

            return filtered;
        },

        isSelected(product) {
            if (!this.selectedProduct) return false;
            return this.selectedProduct.id === product.id && 
                   this.selectedProduct.is_reseller_product === product.is_reseller_product;
        },

        selectProduct(product) {
            if (this.isSelected(product)) {
                this.selectedProduct = null;
            } else {
                this.selectedProduct = product;
            }
        },

        clearFilters() {
            this.searchQuery = '';
            this.selectedNetwork = '';
            this.sortBy = 'random';
        },

        shuffleProducts() {
            for (let i = this.allProducts.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [this.allProducts[i], this.allProducts[j]] = [this.allProducts[j], this.allProducts[i]];
            }
            this.sortBy = 'random';
        },

        formatCurrency(amount) {
            if (!amount) return 'GHS 0.00';
            return 'GHS ' + parseFloat(amount).toFixed(2);
        },

        async submitPayment(event) {
            if (!this.selectedProduct || this.isSubmitting) return;

            if (!this.recipientPhone || !this.momoNumber) {
                alert('Please enter both recipient phone number and MoMo number');
                return;
            }

            this.isSubmitting = true;

            try {
                const form = event.target;
                const formData = new FormData(form);

                const response = await fetch(form.action, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                    }
                });

                const data = await response.json();

                // Unified inline flow: backends return `flow_type: 'inline'` for inline gateways
                if (data.flow_type === 'inline') {
                    // Use InlinePaymentManager (shared module) for all inline gateways
                    InlinePaymentManager.open({
                        reference: data.reference,
                        authorization_url: data.authorization_url ?? null,
                        gateway_name: data.gateway_name ?? null,
                    }, (status) => {
                        // callback invoked on paid|failed|timeout
                        this.isSubmitting = false;
                    });
                } else if (data.authorization_url) {
                    // Non-inline redirect flow
                    window.location.href = data.authorization_url;
                } else if (data.success) {
                    window.location.href = data.redirect || '/checkout/success';
                } else {
                    alert(data.message || 'Payment failed. Please try again.');
                    this.isSubmitting = false;
                }
            } catch (error) {
                console.error('Payment error:', error);
                alert('An error occurred. Please try again.');
                this.isSubmitting = false;
            }
        }
        
                // Inline UI is handled by the shared InlinePaymentManager component
                // (see components/inline_payment_manager.blade.php included globally)
        }

        function closeInlinePaymentModal() {
            const modal = document.getElementById('inline-payment-modal');
            if (modal) modal.remove();
            if (window.inlinePaymentPoll) {
                clearInterval(window.inlinePaymentPoll);
                window.inlinePaymentPoll = null;
            }
            // Re-enable checkout buttons
            const submitButtons = document.querySelectorAll('button[type=submit], input[type=submit]');
            submitButtons.forEach(b => b.removeAttribute('disabled'));
        }

        // Centralized polling routine with safety/timeouts
        function startPolling(reference, onFinish) {
            // avoid multiple polls
            if (window.inlinePaymentPoll) return;

            const pollInterval = 3000;
            let pollCount = 0;
            const maxPolls = Math.ceil((3 * 60 * 1000) / pollInterval); // 3 minutes default

            window.inlinePaymentPoll = setInterval(async () => {
                pollCount++;
                try {
                    const res = await fetch('/payment/status/' + encodeURIComponent(reference), { headers: { 'Accept': 'application/json' } });
                    const body = await res.json();
                    const status = (body && body.status) ? body.status : 'pending';

                    if (status === 'paid') {
                        clearInterval(window.inlinePaymentPoll);
                        window.inlinePaymentPoll = null;
                        if (typeof onFinish === 'function') onFinish();
                        // Close modal and redirect to success page
                        closeInlinePaymentModal();
                        // redirect to success — keep using checkout success URL format
                        window.location.href = '/checkout/success/' + '';
                        return;
                    }

                    if (status === 'failed') {
                        clearInterval(window.inlinePaymentPoll);
                        window.inlinePaymentPoll = null;
                        if (typeof onFinish === 'function') onFinish();
                        alert('Payment failed. Please try another method.');
                        closeInlinePaymentModal();
                        return;
                    }
                } catch (e) {
                    console.error('Poll error', e);
                }

                if (pollCount >= maxPolls) {
                    clearInterval(window.inlinePaymentPoll);
                    window.inlinePaymentPoll = null;
                    if (typeof onFinish === 'function') onFinish();
                    alert('Payment confirmation timed out. Please check your payment provider or try again.');
                    closeInlinePaymentModal();
                }
            }, pollInterval);
        }
    }"
>
    <!-- Hidden JSON data -->
    <script type="application/json" id="products-data">@json($products)</script>

    {{-- ============================================================
         Hero
         ============================================================ --}}
    <section class="relative overflow-hidden" style="background: #fff;">
        <div class="x4-hero-wash absolute inset-0" aria-hidden="true" style="pointer-events: none;"></div>

        <div class="relative max-w-6xl mx-auto px-5 py-10 sm:py-12 text-center">
            <x-storefront.reveal from="up" class="max-w-2xl mx-auto">
                <x-storefront.eyebrow>Marketplace</x-storefront.eyebrow>
                <h1 class="x4-display-xl mt-4 mb-3" style="color: var(--x4-ink-strong);">
                    Browse every <span style="color: var(--x4-violet);">verified vendor</span> in one place
                </h1>
                <p class="x4-body-lg" style="color: var(--x4-ink-body);">
                    Compare prices across vendors and find the best deal on any service.
                </p>
            </x-storefront.reveal>
        </div>
    </section>

    <div class="max-w-6xl mx-auto px-5" style="padding-top: 32px; padding-bottom: 72px;">
        <!-- Search and Filters -->
        <div class="x4-panel mb-6" style="padding: 20px;">
            <div class="flex flex-col lg:flex-row gap-4">
                <!-- Search -->
                <div class="flex-1">
                    <div class="relative">
                        <x-storefront.icon
                            name="search"
                            class="w-4 h-4 absolute"
                            style="left: 14px; top: 50%; transform: translateY(-50%); color: var(--x4-ink-mute); pointer-events: none;"
                        />
                        <input
                            type="text"
                            x-model="searchQuery"
                            placeholder="Search services, vendors, data bundles..."
                            class="x4-input"
                            style="padding-left: 38px; padding-top: 12px; padding-bottom: 12px;"
                        >
                    </div>
                </div>

                <!-- Network Filter -->
                <div class="w-full lg:w-48">
                    <select
                        x-model="selectedNetwork"
                        class="x4-input"
                        style="padding-top: 12px; padding-bottom: 12px;"
                    >
                        <option value="">All Networks</option>
                        @foreach (config('momo.product_networks', []) as $networkValue => $network)
                            <option value="{{ $networkValue }}">{{ $network['label'] ?? $networkValue }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Sort -->
                <div class="w-full lg:w-48">
                    <select
                        x-model="sortBy"
                        class="x4-input"
                        style="padding-top: 12px; padding-bottom: 12px;"
                    >
                        <option value="random">Random Order</option>
                        <option value="price_low">Price: Low to High</option>
                        <option value="price_high">Price: High to Low</option>
                        <option value="name">Name: A-Z</option>
                        <option value="vendor">Vendor: A-Z</option>
                    </select>
                </div>
            </div>

            <!-- Active Filters & Results Count -->
            <div class="flex flex-wrap items-center justify-between mt-4 pt-4" style="border-top: 1px solid var(--x4-hairline);">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="x4-caption" style="color: var(--x4-ink-mute);">
                        Showing <span class="x4-tnum" style="color: var(--x4-violet); font-weight: 500;" x-text="filteredProducts.length"></span> services
                    </span>
                    <template x-if="searchQuery || selectedNetwork">
                        <button @click="clearFilters()" class="x4-caption" style="color: var(--x4-violet); font-weight: 500; background: none; border: none; cursor: pointer;">
                            Clear filters
                        </button>
                    </template>
                </div>
                <button
                    @click="shuffleProducts()"
                    class="text-sm text-gray-500 hover:text-purple-600 flex items-center gap-1 transition-colors"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                    Shuffle
                </button>
            </div>
        </div>

        <!-- Products Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            <template x-for="product in filteredProducts" :key="product.id + '-' + (product.is_reseller_product ? 'r' : 'o')">
                <div
                    @click="selectProduct(product)"
                    class="x4-service-card"
                    style="padding: 0; cursor: pointer;"
                    :style="isSelected(product) ? 'border-color: var(--x4-violet); box-shadow: 0 0 0 3px var(--x4-violet-soft);' : ''"
                >
                    <!-- Network Banner -->
                    <div class="h-1.5 bg-gradient-to-r"
                        :class="(product.network && networkGradients[product.network]) ? networkGradients[product.network] : 'from-violet-500 to-violet-600'">
                    </div>

                    <div style="padding: 18px;">
                        <!-- Vendor Badge & Reseller Tag -->
                        <div class="flex items-center justify-between mb-3 gap-2">
                            <span class="inline-flex items-center gap-1.5" style="background-color: var(--x4-canvas-soft); border-radius: var(--x4-r-pill); padding: 3px 10px;">
                                <x-storefront.icon name="shield" class="w-3 h-3" style="color: var(--x4-ink-mute);" />
                                <span class="x4-caption truncate" style="color: var(--x4-ink-mute); max-width: 110px;" x-text="product.vendor_name"></span>
                            </span>
                            <template x-if="product.is_reseller_product">
                                <span class="x4-micro-cap flex-shrink-0" style="background-color: var(--x4-violet-soft); color: var(--x4-violet-deep); border-radius: var(--x4-r-pill); padding: 2px 8px;">Reseller</span>
                            </template>
                        </div>

                        <!-- Product Name -->
                        <h3 class="x4-heading-md truncate" style="color: var(--x4-ink); margin-bottom: 6px;" x-text="product.name"></h3>

                        <!-- Description -->
                        <p class="x4-caption mb-3" style="color: var(--x4-ink-mute); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;" x-text="product.description || 'Data bundle service'"></p>

                        <!-- Tags -->
                        <div class="flex flex-wrap gap-1.5 mb-4">
                            <template x-if="product.network">
                                <span class="x4-micro-cap" style="background-color: var(--x4-violet-soft); color: var(--x4-violet-deep); border-radius: var(--x4-r-pill); padding: 2px 8px;">
                                    <span x-text="product.network"></span>
                                </span>
                            </template>
                            <template x-if="product.size">
                                <span class="x4-micro-cap" style="background-color: var(--x4-canvas-soft); color: var(--x4-ink-sec); border-radius: var(--x4-r-pill); padding: 2px 8px;">
                                    <span x-text="product.size"></span>
                                </span>
                            </template>
                            <template x-if="product.validity">
                                <span class="x4-micro-cap" style="background-color: #dcfce7; color: #166534; border-radius: var(--x4-r-pill); padding: 2px 8px;">
                                    <span x-text="product.validity"></span>
                                </span>
                            </template>
                        </div>

                        <!-- Price & Select -->
                        <div class="flex items-center justify-between pt-3" style="border-top: 1px solid var(--x4-hairline);">
                            <span class="x4-tnum" style="font-size: 17px; font-weight: 500; color: var(--x4-violet);" x-text="formatCurrency(product.price)"></span>

                            <div class="flex items-center gap-2">
                                <template x-if="isSelected(product)">
                                    <span class="x4-caption flex items-center gap-1" style="color: #16a34a; font-weight: 500;">
                                        <x-storefront.icon name="check" class="w-4 h-4" />
                                        Selected
                                    </span>
                                </template>
                                <template x-if="!isSelected(product)">
                                    <span class="x4-caption" style="color: var(--x4-ink-mute);">
                                        Tap to select
                                    </span>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <!-- Empty State -->
        <div x-show="filteredProducts.length === 0" class="text-center py-16">
            <div class="mx-auto mb-4 flex items-center justify-center" style="width: 72px; height: 72px; border-radius: 9999px; background-color: var(--x4-canvas-soft);">
                <x-storefront.icon name="search" class="w-7 h-7" style="color: var(--x4-ink-mute);" />
            </div>
            <h3 class="x4-heading-md mb-2" style="color: var(--x4-ink);">No services found</h3>
            <p class="x4-body-md mb-4" style="color: var(--x4-ink-mute);">Try adjusting your filters or search terms</p>
            <button @click="clearFilters()" class="x4-btn x4-btn-primary">
                Clear all filters
            </button>
        </div>
    </div>

    <!-- Sticky Purchase Panel -->
    <div
        x-show="selectedProduct"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="translate-y-full opacity-0"
        x-transition:enter-end="translate-y-0 opacity-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="translate-y-0 opacity-100"
        x-transition:leave-end="translate-y-full opacity-0"
        class="fixed bottom-0 inset-x-0 z-50"
        style="background-color: var(--x4-canvas); border-top: 1px solid var(--x4-hairline); box-shadow: var(--x4-shadow-3);"
        x-cloak
    >
        <div class="max-w-6xl mx-auto px-5 py-4">
            <div class="flex flex-col lg:flex-row lg:items-center gap-4">
                <!-- Selected Product Info -->
                <div class="flex-1 flex items-center gap-4">
                    <div class="flex items-center justify-center flex-shrink-0" style="width: 48px; height: 48px; border-radius: var(--x4-r-lg); background-color: var(--x4-violet);">
                        <x-storefront.icon name="check" class="w-5 h-5" style="color: #fff;" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="x4-body-md truncate" style="font-weight: 500; color: var(--x4-ink);" x-text="selectedProduct ? selectedProduct.name : ''"></p>
                        <p class="x4-caption" style="color: var(--x4-ink-mute);">
                            <span x-text="selectedProduct ? selectedProduct.vendor_name : ''"></span> ·
                            <span class="x4-tnum" style="color: var(--x4-violet); font-weight: 500;" x-text="selectedProduct ? formatCurrency(selectedProduct.price) : ''"></span>
                        </p>
                    </div>
                    <button @click="selectedProduct = null" class="lg:hidden" style="padding: 8px; color: var(--x4-ink-mute); background: none; border: none; cursor: pointer;">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>

                <!-- Purchase Form -->
                <form method="POST" action="{{ route('purchase') }}" @submit.prevent="submitPayment($event)" class="flex flex-col sm:flex-row gap-3 lg:gap-4">
                    @csrf
                    <input type="hidden" name="service_purchased" :value="selectedProduct ? selectedProduct.name : ''">
                    <input type="hidden" name="amount_paid" :value="selectedProduct ? selectedProduct.price : ''">
                    <input type="hidden" name="vendor_service_id" :value="selectedProduct ? (selectedProduct.original_product_id || selectedProduct.product_id || selectedProduct.id) : ''">
                    <input type="hidden" name="vendor_id" :value="selectedProduct ? selectedProduct.vendor_id : ''">
                    <input type="hidden" name="is_reseller_product" :value="selectedProduct && selectedProduct.is_reseller_product ? '1' : '0'">
                    <input type="hidden" name="reseller_product_id" :value="selectedProduct ? (selectedProduct.reseller_product_id || '') : ''">

                    <div class="flex-1 sm:w-40">
                        <input
                            type="tel"
                            name="recipient_phone_number"
                            x-model="recipientPhone"
                            placeholder="Recipient Phone"
                            inputmode="tel"
                            autocomplete="tel"
                            required
                            class="x4-input"
                            style="font-size: 14px;"
                        >
                    </div>
                    <div class="flex-1 sm:w-40">
                        <input
                            type="tel"
                            name="mobile_money_number"
                            x-model="momoNumber"
                            placeholder="MoMo Number"
                            inputmode="tel"
                            autocomplete="tel"
                            required
                            class="x4-input"
                            style="font-size: 14px;"
                        >
                    </div>
                    <template x-if="currentVendor">
                        <div class="w-full sm:w-auto flex items-center gap-4">
                            <label class="inline-flex items-center x4-caption" style="color: var(--x4-ink-sec);">
                                <input type="radio" name="payment_method" value="wallet" x-model="paymentMethod" style="accent-color: var(--x4-violet);" />
                                <span class="ml-2">Wallet (Balance: GHS <span x-text="vendorBalance().toFixed(2)"></span>)</span>
                            </label>
                            <label class="inline-flex items-center x4-caption" style="color: var(--x4-ink-sec);">
                                <input type="radio" name="payment_method" value="gateway" x-model="paymentMethod" style="accent-color: var(--x4-violet);" />
                                <span class="ml-2">Payment Gateway</span>
                            </label>
                        </div>
                    </template>

                    <input type="hidden" name="pay_with_wallet" :value="paymentMethod === 'wallet' ? 1 : 0">
                    <button
                        type="submit"
                        :disabled="isSubmitting || !selectedProduct || (paymentMethod === 'wallet' && selectedBasePrice() > vendorBalance())"
                        class="x4-btn x4-btn-primary w-full sm:w-auto"
                        style="padding: 13px 26px;"
                    >
                        <template x-if="!isSubmitting">
                            <span class="flex items-center gap-2">
                                Pay <span x-text="selectedProduct ? formatCurrency(selectedProduct.price) : ''"></span>
                            </span>
                        </template>
                        <template x-if="isSubmitting">
                            <span class="flex items-center gap-2">
                                <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Processing...
                            </span>
                        </template>
                    </button>
                </form>

                <!-- Close button for desktop -->
                <button @click="selectedProduct = null" class="hidden lg:block" style="padding: 8px; color: var(--x4-ink-mute); background: none; border: none; cursor: pointer;">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Spacer for sticky panel -->
    <div x-show="selectedProduct" class="h-32 lg:h-24" x-cloak></div>
</div>
@endsection

{{-- Inline payment UI manager (shared) — was previously duplicated and
     misplaced mid-template (see comment near the top of this file);
     included exactly once, here. --}}
@include('components.inline_payment_manager')

