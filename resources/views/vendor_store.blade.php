{{--
    vendor_store.blade.php
    AJAX-driven vendor storefront — Category -> Service -> Package -> Checkout.

    Visual redesign only: every Alpine binding, route, hidden input, and
    ref/id used by the `vendorStore()` and `orderTracker()` components
    (defined in layouts/app.blade.php and the script at the bottom of this
    file) is preserved verbatim. Selectable elements are written as plain
    HTML rather than the `x-storefront.*` Blade components, because a
    `:class`/`:disabled` attribute on a `<x-...>` component tag is
    evaluated as a PHP expression by the Blade compiler and would collide
    with Alpine's identical colon syntax.
--}}

@extends('layouts.app')

@section('title', $vendor->name . ' — XTRA4U Vendor Store')
@section('description', 'Shop data bundles, airtime, ECG tokens, and more from ' . $vendor->name . ' on XTRA4U.')

{{-- Scope the storefront design system to this page only. --}}
@section('body-class', 'x4')

@php
    $shopUrl = route('storefront.vendor', ['vendor' => $vendor->vendor_code]);

    // config/storefront.php icon names -> the storefront icon set, same
    // mapping used by the marketplace homepage so category iconography
    // stays identical across the customer journey.
    $categoryIconMap = [
        'signal' => 'wifi',
        'bolt' => 'zap',
        'bag' => 'ticket',
        'chart' => 'grad',
        'clipboard' => 'id',
    ];
    $categoryConfig = config('storefront.categories', []);
@endphp

@section('site-header')
    <x-storefront.header :shop-url="$shopUrl" :show-dashboard-button="$isStoreOwner ?? false" />
@endsection

@section('site-footer')
    <x-storefront.footer :shop-url="$shopUrl" :show-vendor-dashboard-link="false" />
@endsection

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
        verifyRoute: '{{ route('checkout.verify') }}',
        vendorCode: '{{ $vendor->vendor_code ?? '' }}',
        vendorPhone: '{{ $vendor->phone_number ?? '' }}',
        initialCategory: {!! json_encode($initialCategory ?? null) !!},
        resultCheckerOrderRoute: '{{ route('result-checkers.checkout', ['vendor' => $vendor->vendor_code ?? '']) }}'
    };
</script>

<div
    class="x4-page"
    style="padding-top: 64px;"
    x-data="typeof vendorStore === 'function' ? vendorStore(window.vendorStoreData) : {}"
    x-init="typeof init === 'function' && init()"
>
    {{-- ============================================================
         Vendor hero banner
         ============================================================ --}}
    <section class="relative overflow-hidden" style="background: #fff;">
        <div class="x4-hero-wash absolute inset-0" aria-hidden="true" style="pointer-events: none;"></div>

        <div class="relative max-w-6xl mx-auto px-5 py-10 sm:py-14 text-center">
            <x-storefront.reveal from="up" class="max-w-2xl mx-auto">
                <x-storefront.eyebrow>Vendor Storefront</x-storefront.eyebrow>

                <h1 class="x4-display-xl mt-4 mb-3" style="color: var(--x4-ink-strong);">
                    Shop with <span style="color: var(--x4-violet);">{{ $vendor->name }}</span>
                </h1>

                <p class="x4-body-lg mb-5" style="color: var(--x4-ink-body);">
                    Explore network bundles, electricity tokens, online vouchers, and more
                    delivered instantly and paid for with Mobile Money.
                </p>

                <div class="flex flex-wrap items-center justify-center gap-2.5">
                    <span
                        class="inline-flex items-center gap-2"
                        style="background-color: var(--x4-violet-soft); border-radius: var(--x4-r-pill); padding: 6px 14px;"
                    >
                        <span class="x4-micro-cap" style="color: var(--x4-violet);">Vendor Code</span>
                        <span class="x4-caption x4-tnum" style="color: var(--x4-violet); font-weight: 500;">{{ $vendor->vendor_code ?? 'N/A' }}</span>
                    </span>

                    @if ($vendor->is_approved)
                        <span
                            class="inline-flex items-center gap-1.5"
                            style="background-color: #dcfce7; color: #166534; border-radius: var(--x4-r-pill); padding: 6px 14px;"
                        >
                            <x-storefront.icon name="shield" class="w-3.5 h-3.5" />
                            <span class="x4-caption" style="font-weight: 500;">Verified Vendor</span>
                        </span>
                    @endif

                    @if ($vendor->phone_number)
                        <span
                            class="inline-flex items-center gap-1.5"
                            style="background-color: var(--x4-canvas-soft); border: 1px solid var(--x4-hairline); border-radius: var(--x4-r-pill); padding: 6px 14px;"
                        >
                            <x-storefront.icon name="phone" class="w-3.5 h-3.5" style="color: var(--x4-ink-mute);" />
                            <span class="x4-caption" style="color: var(--x4-ink-sec);">{{ $vendor->phone_number }}</span>
                        </span>
                    @endif
                </div>
            </x-storefront.reveal>
        </div>
    </section>

    <div class="max-w-6xl mx-auto px-5" style="padding-top: 32px; padding-bottom: 72px;">

        {{-- ============================================================
             Order status tracker (collapsible)
             ============================================================ --}}
        <div x-data="orderTracker()" class="x4-panel mb-6" style="overflow: hidden;">
            <button
                type="button"
                @click="isOpen = !isOpen"
                class="w-full flex items-center justify-between gap-3"
                style="padding: 14px 18px; background: none; border: none; cursor: pointer; text-align: left;"
            >
                <span class="flex items-center gap-3">
                    <span
                        aria-hidden="true"
                        class="flex items-center justify-center flex-shrink-0"
                        style="width: 34px; height: 34px; border-radius: var(--x4-r-md); background-color: var(--x4-violet); color: #fff;"
                    >
                        <x-storefront.icon name="clock" class="w-4 h-4" />
                    </span>
                    <span class="x4-body-md" style="font-weight: 500; color: var(--x4-ink);">Track Your Order</span>
                </span>

                <svg
                    class="w-4 h-4 transition-transform duration-200"
                    :class="isOpen ? 'rotate-180' : ''"
                    style="color: var(--x4-ink-mute);"
                    fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>

            <div x-show="isOpen" x-collapse x-cloak style="border-top: 1px solid var(--x4-hairline);">
                <div style="padding: 16px 18px;">
                    <form @submit.prevent="checkStatus" class="flex gap-2">
                        <div class="flex-1 relative">
                            <label for="order-track-phone" class="sr-only">Enter recipient phone number</label>
                            <x-storefront.icon
                                name="phone"
                                class="w-4 h-4 absolute"
                                style="left: 12px; top: 50%; transform: translateY(-50%); color: var(--x4-ink-mute); pointer-events: none;"
                            />
                            <input
                                type="tel"
                                id="order-track-phone"
                                name="order_track_phone"
                                x-model="phone"
                                placeholder="Enter recipient phone number"
                                autocomplete="tel"
                                class="x4-input"
                                style="padding-left: 36px;"
                            >
                        </div>
                        <button
                            type="submit"
                            :disabled="loading"
                            class="x4-btn x4-btn-primary"
                            style="padding: 10px 18px;"
                        >
                            <template x-if="loading">
                                <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </template>
                            <span x-text="loading ? 'Checking…' : 'Check'"></span>
                        </button>
                    </form>

                    <div class="mt-3" x-show="searched" x-cloak>
                        <template x-if="error || (orders.length === 0 && searched)">
                            <p class="x4-caption text-center" style="color: var(--x4-ink-mute); padding: 12px 0;">
                                <span x-text="error || 'No orders found for this number.'"></span>
                            </p>
                        </template>

                        <template x-if="orders.length > 0">
                            <div class="space-y-2" style="max-height: 16rem; overflow-y: auto;">
                                <template x-for="order in orders" :key="order.id">
                                    <div
                                        class="flex items-center justify-between gap-3"
                                        style="padding: 10px 12px; background-color: var(--x4-canvas-soft); border-radius: var(--x4-r-md);"
                                    >
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-2">
                                                <span class="x4-caption truncate" style="color: var(--x4-ink); font-weight: 500;" x-text="order.service"></span>
                                                <span class="x4-micro-cap" style="color: var(--x4-ink-mute);" x-text="'GH₵' + order.amount"></span>
                                            </div>
                                            <div class="x4-micro-cap mt-0.5" style="color: var(--x4-ink-mute); text-transform: none;">
                                                <span x-text="order.date"></span> · <span x-text="order.time"></span>
                                            </div>
                                        </div>
                                        <span
                                            class="inline-flex items-center flex-shrink-0"
                                            style="padding: 3px 10px; border-radius: var(--x4-r-pill);"
                                            :class="order.status_color.bg + ' ' + order.status_color.text"
                                        >
                                            <span class="w-1.5 h-1.5 rounded-full mr-1.5" :class="order.status_color.dot"></span>
                                            <span class="x4-micro-cap" style="text-transform: none;" x-text="order.status_label"></span>
                                        </span>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        {{-- ============================================================
             Category selector
             ============================================================ --}}
        <div class="x4-panel mb-6" x-show="!isCheckoutOnlyMode" x-cloak style="padding: 24px;">
            <x-storefront.eyebrow>Category Selector</x-storefront.eyebrow>
            <h2 class="x4-heading-lg mt-3 mb-4" style="color: var(--x4-ink);">Choose a category</h2>

            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4">
                @if (count($categories))
                    @foreach ($categories as $index => $category)
                        @php
                            $categoryClosed = ($serviceStatuses[$category['value']] ?? true) === false;
                            $iconKey = $categoryConfig[$category['value']]['icon'] ?? null;
                            $iconName = $categoryIconMap[$iconKey] ?? 'wifi';
                        @endphp

                        @if ($categoryClosed)
                            <div
                                class="x4-cat-tile is-closed"
                                title="{{ $serviceClosedMessage ?? 'Temporarily unavailable' }}"
                            >
                                <span class="x4-cat-icon"><x-storefront.icon :name="$iconName" class="w-5 h-5" /></span>
                                <span class="x4-caption" style="font-weight: 500;">{{ $category['label'] }}</span>
                                <span
                                    class="x4-micro-cap"
                                    style="background-color: #fef3c7; color: #92400e; border-radius: var(--x4-r-pill); padding: 2px 8px;"
                                >Temporarily closed</span>
                            </div>
                        @else
                            <button
                                type="button"
                                class="x4-cat-tile"
                                :class="selectedCategory && selectedCategory.value === '{{ $category['value'] }}' ? 'x4-cat-tile is-selected' : 'x4-cat-tile'"
                                @click="selectCategory(categories[{{ $index }}])"
                            >
                                <span class="x4-cat-icon"><x-storefront.icon :name="$iconName" class="w-5 h-5" /></span>
                                <span class="x4-caption" style="font-weight: 500; color: var(--x4-ink);">{{ $category['label'] }}</span>
                                @if (($category['serviceCount'] ?? 0) > 0)
                                    <span class="x4-micro-cap" style="color: var(--x4-ink-mute);">{{ $category['serviceCount'] }} available</span>
                                @endif
                            </button>
                        @endif
                    @endforeach
                @else
                    <p class="x4-body-md" style="color: var(--x4-ink-mute); grid-column: 1 / -1;">
                        No categories available. Please contact the vendor.
                    </p>
                @endif
            </div>
        </div>

        {{-- ============================================================
             Service -> Package -> Checkout
             ============================================================ --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

            {{-- 1) CHOOSE SERVICE --}}
            <div
                class="x4-panel"
                style="padding: 24px;"
                x-show="step >= 2 && !isCheckoutOnlyMode"
                x-cloak
                x-transition
            >
                <h3 class="x4-heading-md mb-4" style="color: var(--x4-ink);">Choose Service</h3>

                <template x-if="loadingServices">
                    <p class="x4-body-md" style="color: var(--x4-ink-mute);">Loading services…</p>
                </template>

                <template x-if="!loadingServices && filteredServices.length">
                    <div class="space-y-2.5">
                        <template x-for="svc in filteredServices" :key="svc.key">
                            <div
                                class="x4-pick-card"
                                :class="selectedService && selectedService.key === svc.key ? 'x4-pick-card is-selected' : 'x4-pick-card'"
                                @click="selectService(svc)"
                            >
                                <img :src="svc.logo || '/images/default-provider.png'" alt="" class="flex-shrink-0" style="width: 40px; height: 40px; border-radius: var(--x4-r-sm); object-fit: cover;">
                                <div class="min-w-0">
                                    <div class="x4-caption truncate" style="color: var(--x4-ink); font-weight: 500;" x-text="svc.name"></div>
                                    <div class="x4-micro-cap mt-0.5" style="color: var(--x4-ink-mute); text-transform: none;" x-text="svc.packages ? svc.packages.length + (svc.packages.length === 1 ? ' package' : ' packages') : ''"></div>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>

                <template x-if="!loadingServices && !filteredServices.length">
                    <p class="x4-body-md" style="color: var(--x4-ink-mute);">No services available for this category.</p>
                </template>
            </div>

            {{-- 2) SELECT PACKAGE / SELECTED PACKAGE --}}
            <div
                id="package-section"
                x-ref="packageSection"
                x-show="step >= 3"
                x-cloak
                x-transition
                class="x4-panel"
                style="padding: 24px;"
            >
                <template x-if="!isCheckoutOnlyMode">
                    <div>
                        <h3 class="x4-heading-md mb-4" style="color: var(--x4-ink);">Select Package</h3>

                        <template x-if="loadingPackages">
                            <p class="x4-body-md" style="color: var(--x4-ink-mute);">Loading packages…</p>
                        </template>

                        <template x-if="!loadingPackages && availablePackages.length">
                            <div class="space-y-3">
                                <template x-for="(pkg, idx) in availablePackages" :key="String(pkg.id) + '_' + idx">
                                    <div
                                        class="x4-pick-card"
                                        :class="selectedPackage && selectedPackage.id === pkg.id ? 'x4-pick-card is-selected' : 'x4-pick-card'"
                                        @click="selectPackage(pkg)"
                                    >
                                        <img :src="selectedService?.logo || '/images/default-provider.png'" alt="" class="flex-shrink-0" style="width: 44px; height: 44px; border-radius: var(--x4-r-sm); object-fit: cover;">

                                        <div class="flex-1 flex justify-between items-start gap-3">
                                            <div class="min-w-0">
                                                <div class="flex items-center gap-2 flex-wrap">
                                                    <span class="x4-caption" style="color: var(--x4-ink); font-weight: 500;" x-text="pkg.name"></span>
                                                    <span
                                                        x-show="pkg.tag" x-text="pkg.tag" x-cloak
                                                        class="x4-micro-cap"
                                                        style="background-color: #dcfce7; color: #166534; border-radius: var(--x4-r-pill); padding: 2px 8px;"
                                                    ></span>
                                                </div>
                                                <div class="x4-micro-cap mt-1" style="color: var(--x4-ink-mute); text-transform: none;" x-text="pkg.size || ''"></div>
                                            </div>

                                            <div class="text-right flex-shrink-0">
                                                <div class="x4-tnum" style="font-size: 16px; font-weight: 500; color: var(--x4-violet);" x-text="formatCurrency(pkg.price)"></div>
                                                <div class="x4-micro-cap mt-1" style="color: #16a34a; text-transform: none;" x-text="pkg.validity || ''"></div>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>

                        <template x-if="!loadingPackages && !availablePackages.length">
                            <p class="x4-body-md" style="color: var(--x4-ink-mute);">No packages available for this service.</p>
                        </template>
                    </div>
                </template>

                <template x-if="isCheckoutOnlyMode">
                    <div>
                        <div class="flex items-center justify-between gap-3 mb-4">
                            <h3 class="x4-heading-md" style="color: var(--x4-ink);">Selected Package</h3>
                            <button
                                type="button"
                                class="x4-caption"
                                style="color: var(--x4-violet); font-weight: 500; background: none; border: none; cursor: pointer;"
                                @click="expandPackages()"
                            >Change package</button>
                        </div>

                        <div class="x4-pick-card is-selected" style="cursor: default;">
                            <img :src="selectedService?.logo || '/images/default-provider.png'" alt="" class="flex-shrink-0" style="width: 44px; height: 44px; border-radius: var(--x4-r-sm); object-fit: cover;">

                            <div class="flex-1 flex justify-between items-start gap-3">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="x4-caption" style="color: var(--x4-ink); font-weight: 500;" x-text="selectedPackage?.name"></span>
                                        <span
                                            x-show="selectedPackage?.tag" x-text="selectedPackage?.tag" x-cloak
                                            class="x4-micro-cap"
                                            style="background-color: #dcfce7; color: #166534; border-radius: var(--x4-r-pill); padding: 2px 8px;"
                                        ></span>
                                    </div>
                                    <div class="x4-micro-cap mt-1" style="color: var(--x4-ink-mute); text-transform: none;" x-text="selectedPackage?.size || ''"></div>
                                </div>

                                <div class="text-right flex-shrink-0">
                                    <div class="x4-tnum" style="font-size: 16px; font-weight: 500; color: var(--x4-violet);" x-text="formatCurrency(selectedPackage?.price)"></div>
                                    <div class="x4-micro-cap mt-1" style="color: #16a34a; text-transform: none;" x-text="selectedPackage?.validity || ''"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            {{-- 3) CHECKOUT --}}
            <div
                id="checkout-section"
                x-ref="checkoutSection"
                x-show="step >= 4"
                x-cloak
                x-transition
                class="x4-panel md:sticky md:top-20 self-start"
                style="padding: 24px;"
            >
                <h3 class="x4-heading-md mb-4" style="color: var(--x4-ink);">Checkout</h3>

                <div style="background-color: var(--x4-canvas-cream); border-radius: var(--x4-r-md); padding: 16px; margin-bottom: 18px;">
                    <div class="flex justify-between x4-caption" style="color: var(--x4-ink-mute);">
                        <span>Category</span>
                        <span style="color: var(--x4-ink); font-weight: 500;" x-text="selectedCategory?.label"></span>
                    </div>
                    <div class="flex justify-between x4-caption mt-2" style="color: var(--x4-ink-mute);">
                        <span>Network</span>
                        <span style="color: var(--x4-ink); font-weight: 500;" x-text="selectedService?.name"></span>
                    </div>
                    <div class="flex justify-between x4-caption mt-2" style="color: var(--x4-ink-mute);">
                        <span>Package</span>
                        <span style="color: var(--x4-ink); font-weight: 500;" x-text="selectedPackage?.size || selectedPackage?.name || selectedPackage?.title"></span>
                    </div>
                    <div class="flex justify-between items-center mt-3 pt-3" style="border-top: 1px solid rgba(0,0,0,0.08);">
                        <span class="x4-body-md" style="color: var(--x4-ink);">Price</span>
                        <span class="x4-tnum" style="font-size: 20px; font-weight: 500; color: var(--x4-violet);" x-text="formatCurrency(selectedPackage?.price * (selectedPackage?.is_results_checker ? quantity : 1))"></span>
                    </div>
                </div>

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

                    <div class="mb-3" x-show="selectedPackage?.is_results_checker" x-cloak>
                        <label for="quantity" class="x4-caption block mb-1.5" style="color: var(--x4-ink-mute);">Quantity</label>
                        <input type="number" id="quantity" name="quantity" x-model.number="quantity" min="1" class="x4-input">
                    </div>

                    <div class="mb-4">
                        <label for="recipient_phone" class="x4-caption block mb-1.5" style="color: var(--x4-ink-mute);">Recipient phone</label>
                        <input
                            type="tel" id="recipient_phone" name="recipient_phone"
                            x-model="recipientPhone" required
                            x-ref="recipientPhoneInput"
                            autocomplete="tel"
                            placeholder="e.g. 0244123456"
                            class="x4-input"
                        >
                    </div>

                    <button
                        type="submit"
                        class="x4-btn x4-btn-primary w-full"
                        style="padding: 13px 22px;"
                        :disabled="submitting || paymentPolling || paymentFailed"
                    >
                        <span x-show="!submitting && !paymentPolling && !paymentFailed">Proceed to Payment</span>
                        <span x-show="submitting" x-cloak>Processing…</span>
                        <span x-show="paymentPolling" x-cloak>Waiting for payment confirmation…</span>
                        <span x-show="paymentFailed" x-cloak>Payment failed</span>
                    </button>
                </form>

                {{-- Inline MoMo modal (for non-redirect gateways like BulkClix) --}}
                <div x-show="momoModalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center">
                    <div class="absolute inset-0" style="background: rgba(13,37,61,0.5);" @click="cancelMomoDetails"></div>
                    <div class="relative w-full max-w-md mx-4" style="background-color: var(--x4-canvas); border-radius: var(--x4-r-xl); box-shadow: var(--x4-shadow-3); padding: 28px;">
                        <h4 class="x4-heading-md mb-4" style="color: var(--x4-ink);">Mobile Money Payment</h4>

                        <div style="background-color: #fffbeb; border: 1px solid #fde68a; border-radius: var(--x4-r-md); padding: 14px; margin-bottom: 16px;">
                            <div class="flex items-start gap-2.5">
                                <x-storefront.icon name="clock" class="w-4 h-4 flex-shrink-0 mt-0.5" style="color: #b45309;" />
                                <div class="x4-caption" style="color: #78350f;">
                                    <p style="font-weight: 500;">Didn't receive the MoMo prompt?</p>
                                    <p class="mt-1">You can approve the payment manually from your MoMo menu:</p>
                                    <ol class="mt-1.5 space-y-0.5" style="padding-left: 18px; list-style: decimal;">
                                        <li>Dial <strong>*170#</strong></li>
                                        <li>Select <strong>6</strong> (My Wallet)</li>
                                        <li>Select <strong>3</strong> (My Approvals)</li>
                                        <li>Approve the pending transaction</li>
                                    </ol>
                                    <p class="mt-1.5 x4-micro-cap" style="text-transform: none; color: #92400e;">
                                        Tip: make sure your phone has signal and enough balance for any MoMo charges.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="payer_phone" class="x4-caption block mb-1.5" style="color: var(--x4-ink-mute);">MoMo number</label>
                            <input
                                type="tel" id="payer_phone" name="payer_phone"
                                x-model="payerPhone" inputmode="tel" autocomplete="tel"
                                placeholder="e.g. 0551234567"
                                class="x4-input"
                            >
                        </div>

                        <div class="mb-5">
                            <label for="payer_network" class="x4-caption block mb-1.5" style="color: var(--x4-ink-mute);">Network</label>
                            <select x-model="payerNetwork" id="payer_network" name="payer_network" class="x4-input">
                                <option value="">Select network</option>
                                <option value="MTN">MTN</option>
                                <option value="TELECEL">Telecel</option>
                                <option value="AIRTELTIGO">AirtelTigo</option>
                            </select>
                        </div>

                        <div class="flex gap-3">
                            <button type="button" class="x4-btn x4-btn-outline flex-1" @click="cancelMomoDetails">Cancel</button>
                            <button type="button" class="x4-btn x4-btn-primary flex-1" @click="confirmMomoDetails">Send Prompt</button>
                        </div>

                        <p class="x4-micro-cap mt-3" style="text-transform: none; color: var(--x4-ink-mute);">
                            Enter the number that should receive the payment prompt.
                        </p>
                    </div>
                </div>

                <p class="x4-caption mt-4" style="color: var(--x4-ink-mute);" x-show="orderMessage" x-text="orderMessage"></p>
            </div>

            {{-- Full-screen payment confirmation overlay (inline gateways) --}}
            <div x-show="paymentPolling" x-cloak class="fixed inset-0 z-50 flex items-center justify-center">
                <div class="absolute inset-0" style="background: rgba(13,37,61,0.6); backdrop-filter: blur(4px);"></div>
                <div class="relative w-full max-w-lg mx-4" style="background-color: var(--x4-canvas); border-radius: var(--x4-r-xl); box-shadow: var(--x4-shadow-3); overflow: hidden;">
                    <div style="padding: 32px;">
                        <div class="flex items-center gap-4">
                            <div class="relative flex-shrink-0 flex items-center justify-center" style="width: 56px; height: 56px; border-radius: var(--x4-r-lg); background-color: var(--x4-violet-soft);">
                                <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" style="color: var(--x4-violet); opacity: 0.35;" aria-hidden="true">
                                    <path d="M12 2a10 10 0 100 20 10 10 0 000-20z" stroke="currentColor" stroke-width="2" />
                                    <path d="M12 6v6l4 2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                <svg class="absolute animate-spin" style="top: -4px; left: -4px; width: 64px; height: 64px; color: var(--x4-violet);" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v3a5 5 0 00-5 5H4z"></path>
                                </svg>
                            </div>

                            <div class="flex-1">
                                <h3 class="x4-heading-md" style="color: var(--x4-ink);">Confirming payment status</h3>
                                <p class="x4-body-md mt-1" style="color: var(--x4-ink-mute);">We sent the MoMo prompt and are checking your payment status. This usually takes a moment.</p>
                            </div>
                        </div>

                        <div class="mt-6" style="background-color: var(--x4-canvas-soft); border: 1px solid var(--x4-hairline); border-radius: var(--x4-r-md); padding: 16px;">
                            <div class="flex items-start gap-3">
                                <span class="flex-shrink-0 flex items-center justify-center" style="width: 28px; height: 28px; border-radius: 9999px; background-color: var(--x4-violet-soft);">
                                    <x-storefront.icon name="clock" class="w-3.5 h-3.5" style="color: var(--x4-violet);" />
                                </span>
                                <div class="x4-caption" style="color: var(--x4-ink-sec);">
                                    <p style="font-weight: 500; color: var(--x4-ink);">Don't close or refresh this page</p>
                                    <p class="mt-0.5">Approve the MoMo prompt on your phone to complete payment. No extra code entry is required here.</p>
                                </div>
                            </div>
                        </div>

                        <div class="mt-6">
                            <div style="height: 6px; width: 100%; border-radius: 9999px; background-color: var(--x4-canvas-soft); overflow: hidden;">
                                <div class="animate-pulse" style="height: 100%; width: 33%; border-radius: 9999px; background-color: var(--x4-violet);"></div>
                            </div>
                            <p class="x4-micro-cap mt-2.5" style="text-transform: none; color: var(--x4-ink-mute);">You will be redirected automatically once payment is confirmed.</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Full-screen failure overlay --}}
            <div x-show="paymentFailed" x-cloak class="fixed inset-0 z-50 flex items-center justify-center">
                <div class="absolute inset-0" style="background: rgba(13,37,61,0.6); backdrop-filter: blur(4px);"></div>
                <div class="relative w-full max-w-lg mx-4" style="background-color: var(--x4-canvas); border-radius: var(--x4-r-xl); box-shadow: var(--x4-shadow-3); overflow: hidden;">
                    <div style="padding: 32px;">
                        <div class="flex items-center gap-4">
                            <div class="flex-shrink-0 flex items-center justify-center" style="width: 56px; height: 56px; border-radius: var(--x4-r-lg); background-color: #fee2e2;">
                                <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" style="color: #dc2626;" aria-hidden="true">
                                    <path d="M12 2a10 10 0 100 20 10 10 0 000-20z" stroke="currentColor" stroke-width="2" opacity="0.25" />
                                    <path d="M15 9l-6 6M9 9l6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </div>

                            <div class="flex-1">
                                <h3 class="x4-heading-md" style="color: var(--x4-ink);">Payment failed</h3>
                                <p class="x4-body-md mt-1" style="color: var(--x4-ink-mute);" x-text="paymentFailureMessage || 'Your payment was not completed. Please try again.'"></p>
                            </div>
                        </div>

                        <div class="mt-6" style="background-color: #fef2f2; border: 1px solid #fecaca; border-radius: var(--x4-r-md); padding: 14px;">
                            <p class="x4-caption" style="color: #991b1b;">You will be returned to checkout so you can try again.</p>
                        </div>

                        <div class="mt-6">
                            <button type="button" class="x4-btn x4-btn-primary w-full" @click="dismissPaymentFailure">Back to Checkout</button>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    {{-- ============================================================
         WhatsApp contact
         ============================================================ --}}
    @if ($vendor->phone_number)
        <section style="background-color: var(--x4-canvas-soft); border-top: 1px solid var(--x4-hairline); padding: 56px 0;">
            <x-storefront.reveal class="max-w-6xl mx-auto px-5">
                <div class="text-center" style="background-color: var(--x4-canvas); border: 1px solid var(--x4-hairline); border-radius: var(--x4-r-xl); box-shadow: var(--x4-shadow-1); padding: 40px 24px;">
                    <h2 class="x4-display-md mb-3" style="color: var(--x4-ink);">Need Help?</h2>
                    <p class="x4-body-lg mb-6" style="color: var(--x4-ink-sec);">
                        Contact {{ $vendor->name }} if your order takes longer than 2 hours.
                    </p>
                    <a
                        href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $vendor->phone_number) }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="x4-btn"
                        style="background-color: #22c55e; color: #fff; border: 1px solid #22c55e; padding: 13px 26px;"
                    >
                        <x-storefront.icon name="whatsapp" class="w-4 h-4" />
                        Contact vendor: {{ $vendor->phone_number }}
                    </a>
                </div>
            </x-storefront.reveal>
        </section>
    @endif
</div>
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
