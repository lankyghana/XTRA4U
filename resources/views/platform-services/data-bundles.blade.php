{{--
    platform-services/data-bundles.blade.php

    Official XTRA4U "Data Bundles" page — the homepage entry point, distinct
    from a vendor's own shared storefront (/store/{vendor_code}).

    This page shows exactly ONE category, already filtered server-side by
    App\Support\PlatformServiceCatalog (see PlatformServiceController). No
    other category's services are ever sent to the browser, so there is no
    category switcher here — unlike vendor_store.blade.php, which
    intentionally shows a vendor's full multi-category catalog.

    The purchase flow (Choose Network -> Select Package -> Checkout, the
    order tracker, MoMo modal, and payment polling/failure overlays) lives in
    <x-storefront.platform-purchase-panel>, shared with every platform
    service page. It reuses the same `vendorStore()` Alpine component and the
    same `checkout.process` / `checkout.verify` routes as the vendor
    storefront, verbatim — no commerce logic is duplicated here, only the
    presentation around it.
--}}

@extends('layouts.app')

@section('title', 'Data Bundles - XTRA4U')
@section('description', 'Buy MTN, Telecel, and AirtelTigo data bundles instantly on XTRA4U. Secure Mobile Money payments, delivered in minutes.')

{{-- Scope the storefront design system to this page only. --}}
@section('body-class', 'x4')

@php
    // "Buy Now" in the header/footer should keep the visitor on this page —
    // never expose the vendor code that actually fulfils this category.
    $shopUrl = route('services.data-bundles');
@endphp

@section('site-header')
    <x-storefront.header :shop-url="$shopUrl" />
@endsection

@section('site-footer')
    <x-storefront.footer :shop-url="$shopUrl" />
@endsection

@section('content')
<script>
    window.vendorStoreData = {
        vendorId: {{ $vendor->id }},
        categories: {!! json_encode([[
            'id' => $category,
            'value' => $category,
            'label' => $label,
            'description' => $categoryConfig['description'] ?? null,
            'serviceCount' => $services->sum(fn ($s) => count($s['packages'] ?? [])),
        ]]) !!},
        services: {!! \Illuminate\Support\Js::from($services) !!},
        requiresInlineMomo: {{ ($requiresInlineMomo ?? false) ? 'true' : 'false' }},
        initialPaymentFailed: {{ session('payment_failed') ? 'true' : 'false' }},
        initialPaymentFailureMessage: {!! json_encode(session('payment_message') ?? null) !!},
        orderRoute: '{{ route('checkout.process') }}',
        verifyRoute: '{{ route('checkout.verify') }}',
        vendorPhone: '{{ $vendor->phone_number ?? '' }}',
        initialCategory: '{{ $category }}',
        // This page never sells result-checker packages, so no route is needed.
        resultCheckerOrderRoute: ''
    };
</script>

<div
    class="x4-page"
    style="padding-top: 64px;"
    x-data="typeof vendorStore === 'function' ? vendorStore(window.vendorStoreData) : {}"
    x-init="typeof init === 'function' && init()"
>
    {{-- ============================================================
         Hero
         ============================================================ --}}
    <section class="relative overflow-hidden" style="background: #fff;">
        <div class="x4-hero-wash absolute inset-0" aria-hidden="true" style="pointer-events: none;"></div>

        <div class="relative max-w-6xl mx-auto px-5 py-10 sm:py-14 text-center">
            <x-storefront.reveal from="up" class="max-w-2xl mx-auto">
                <x-storefront.eyebrow>{{ $label }}</x-storefront.eyebrow>

                <h1 class="x4-display-xl mt-4 mb-3" style="color: var(--x4-ink-strong);">
                    Fast and secure <span style="color: var(--x4-violet);">data bundles</span> from XTRA4U
                </h1>

                <p class="x4-body-lg mb-5" style="color: var(--x4-ink-body);">
                    MTN, Telecel, and AirtelTigo bundles, delivered instantly and paid for with Mobile Money.
                </p>

                <div class="flex flex-wrap items-center justify-center gap-2.5">
                    <span
                        class="inline-flex items-center gap-1.5"
                        style="background-color: #dcfce7; color: #166534; border-radius: var(--x4-r-pill); padding: 6px 14px;"
                    >
                        <x-storefront.icon name="shield" class="w-3.5 h-3.5" />
                        <span class="x4-caption" style="font-weight: 500;">Official XTRA4U Service</span>
                    </span>

                    <span
                        class="inline-flex items-center gap-1.5"
                        style="background-color: var(--x4-violet-soft); border-radius: var(--x4-r-pill); padding: 6px 14px;"
                    >
                        <x-storefront.icon name="zap" class="w-3.5 h-3.5" style="color: var(--x4-violet);" />
                        <span class="x4-caption" style="color: var(--x4-violet); font-weight: 500;">Instant delivery</span>
                    </span>
                </div>
            </x-storefront.reveal>
        </div>
    </section>

    <div class="max-w-6xl mx-auto px-5" style="padding-top: 32px; padding-bottom: 72px;">
        <x-storefront.platform-purchase-panel service-label="Network" />
    </div>

    {{-- ============================================================
         How it works
         ============================================================ --}}
    <section style="background-color: var(--x4-canvas-soft); border-top: 1px solid var(--x4-hairline); padding: 72px 0;">
        <div class="max-w-6xl mx-auto px-5">
            <x-storefront.reveal class="text-center mb-12">
                <x-storefront.eyebrow>How it works</x-storefront.eyebrow>
                <h2 class="x4-display-xl mt-4 mb-3" style="color: var(--x4-ink);">Three steps. Under 5 minutes.</h2>
                <p class="x4-body-lg" style="color: var(--x4-ink-sec);">Get data on your phone without leaving home.</p>
            </x-storefront.reveal>

            <div class="relative grid md:grid-cols-3 gap-6">
                <div
                    aria-hidden="true"
                    class="hidden md:block absolute"
                    style="top: 20px; left: calc(16.67% + 40px); right: calc(16.67% + 40px); height: 1px; background-color: var(--x4-hairline);"
                ></div>

                @foreach ([
                    ['n' => '01', 'title' => 'Choose Your Network', 'body' => 'Pick MTN, Telecel, or AirtelTigo and select the bundle that fits your needs.'],
                    ['n' => '02', 'title' => 'Enter Recipient Number', 'body' => 'Add the phone number that should receive the data bundle.'],
                    ['n' => '03', 'title' => 'Get Your Bundle', 'body' => 'Pay with Mobile Money and receive your data within minutes.'],
                ] as $i => $step)
                    <x-storefront.reveal :delay="$i * 110">
                    <div style="background-color: var(--x4-canvas); border: 1px solid var(--x4-hairline); border-radius: var(--x4-r-lg); padding: 28px; box-shadow: var(--x4-shadow-1); height: 100%;">
                        <div
                            class="x4-tnum w-10 h-10 flex items-center justify-center mb-5 relative z-10"
                            style="border-radius: 8px; font-size: 14px; font-weight: 400;
                                background-color: {{ $i === 0 ? 'var(--x4-violet)' : 'var(--x4-canvas-soft)' }};
                                color: {{ $i === 0 ? '#fff' : 'var(--x4-ink-mute)' }};
                                {{ $i === 0 ? 'box-shadow: 0 0 0 4px var(--x4-violet-soft);' : '' }}"
                        >{{ $step['n'] }}</div>

                        <h3 class="x4-heading-md mb-2" style="color: var(--x4-ink);">{{ $step['title'] }}</h3>
                        <p class="x4-body-md" style="color: var(--x4-ink-sec);">{{ $step['body'] }}</p>
                    </div>
                    </x-storefront.reveal>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ============================================================
         Why buy data bundles on XTRA4U
         ============================================================ --}}
    <section style="background-color: var(--x4-canvas); padding: 72px 0;">
        <div class="max-w-6xl mx-auto px-5">
            <x-storefront.reveal class="max-w-xl mb-10">
                <x-storefront.eyebrow>Why XTRA4U</x-storefront.eyebrow>
                <h2 class="x4-display-xl mt-4 mb-3" style="color: var(--x4-ink);">
                    Stay connected, <span style="color: var(--x4-violet);">at the best price.</span>
                </h2>
                <p class="x4-body-lg" style="color: var(--x4-ink-sec);">
                    Skip the top-up queues and buy data straight from your phone, anytime.
                </p>
            </x-storefront.reveal>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                @foreach ([
                    ['icon' => 'zap', 'title' => 'Instant Delivery', 'body' => 'Bundles are activated within minutes of payment.'],
                    ['icon' => 'shield', 'title' => 'Secure Payments', 'body' => 'Every transaction is processed through encrypted Mobile Money.'],
                    ['icon' => 'wifi', 'title' => 'All Networks', 'body' => 'MTN, Telecel, and AirtelTigo bundles in one place.'],
                    ['icon' => 'check', 'title' => 'Verified Service', 'body' => 'Fulfilled by a verified XTRA4U vendor, not a random reseller.'],
                ] as $i => $feature)
                    <x-storefront.reveal :delay="$i * 80">
                    <div class="text-center" style="background-color: var(--x4-canvas-soft); border: 1px solid var(--x4-hairline); border-radius: var(--x4-r-lg); padding: 28px 20px; height: 100%;">
                        <div class="mx-auto mb-4 flex items-center justify-center" style="width: 48px; height: 48px; border-radius: 9999px; background-color: var(--x4-violet-soft);">
                            <x-storefront.icon :name="$feature['icon']" class="w-5 h-5" style="color: var(--x4-violet);" />
                        </div>
                        <h3 style="font-size: 14px; font-weight: 500; color: var(--x4-ink); margin-bottom: 6px;">{{ $feature['title'] }}</h3>
                        <p class="x4-caption" style="color: var(--x4-ink-mute); line-height: 1.5;">{{ $feature['body'] }}</p>
                    </div>
                    </x-storefront.reveal>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ============================================================
         Trust / Support
         ============================================================ --}}
    <section style="background-color: var(--x4-canvas-soft); border-top: 1px solid var(--x4-hairline); padding: 56px 0;">
        <x-storefront.reveal class="max-w-6xl mx-auto px-5">
            <div class="text-center" style="background-color: var(--x4-canvas); border: 1px solid var(--x4-hairline); border-radius: var(--x4-r-xl); box-shadow: var(--x4-shadow-1); padding: 40px 24px;">
                <h2 class="x4-display-md mb-3" style="color: var(--x4-ink);">Need Help?</h2>
                <p class="x4-body-lg mb-6" style="color: var(--x4-ink-sec);">
                    Contact support if your order takes longer than 2 hours.
                </p>
                @if ($vendor->phone_number)
                    <a
                        href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $vendor->phone_number) }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="x4-btn"
                        style="background-color: #22c55e; color: #fff; border: 1px solid #22c55e; padding: 13px 26px;"
                    >
                        <x-storefront.icon name="whatsapp" class="w-4 h-4" />
                        Contact support: {{ $vendor->phone_number }}
                    </a>
                @endif
            </div>
        </x-storefront.reveal>
    </section>
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
