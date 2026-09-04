@extends('layouts.app')

@section('title', 'XTRA4U - Your Digital Services Platform')
@section('description', 'Connect with trusted vendors across Ghana. Secure transactions, verified services, and seamless digital experiences.')

{{-- Scope the storefront design system to this page only. --}}
@section('body-class', 'x4')

@php
    /*
     * Every call to action on this page routes into the existing storefront.
     * `MainStore::vendor()` (supplied by StorefrontController@index) resolves
     * the flagship vendor; when no vendor exists we fall back to the
     * marketplace, exactly as this page did before the redesign.
     */
    $shopUrl = $mainStore
        ? route('storefront.vendor', ['vendor' => $mainStore->vendor_code])
        : route('checkout.show');

    /*
     * The service grid is driven by the platform's own category
     * configuration, so it reflects whatever the application actually
     * offers rather than a fixed list copied from the design.
     */
    $categories = config('storefront.categories', []);

    // config/storefront.php icon names -> this page's inline SVG set.
    $iconMap = [
        'signal' => 'wifi',
        'bolt' => 'zap',
        'bag' => 'ticket',
        'chart' => 'grad',
        'clipboard' => 'id',
    ];

    $badgeMap = [
        'data' => 'Most Popular',
        'results' => 'Bulk Available',
    ];

    /*
     * Every configured category now has a dedicated platform page
     * (App\Http\Controllers\PlatformServiceController). Each one resolves
     * its own admin-assigned vendor (App\Support\PlatformServiceVendor) and
     * degrades gracefully if unconfigured/unavailable, so this card grid
     * doesn't need to check availability itself — unlike the old AFA-only
     * check this replaced, which had to because it linked straight to a
     * specific vendor's form.
     */
    $categoryRoutes = [
        'data' => 'services.data-bundles',
        'ecg' => 'services.ecg',
        'shop' => 'services.shop',
        'results' => 'result-checkers.entry',
        'afa' => 'services.afa-registration',
    ];

    $serviceCards = collect($categories)->map(function ($category, $key) use ($shopUrl, $iconMap, $badgeMap, $categoryRoutes) {
        // A category without a dedicated route yet falls back to the
        // flagship store, exactly as every card did before this feature.
        $href = isset($categoryRoutes[$key]) ? route($categoryRoutes[$key]) : $shopUrl;

        return [
            'name' => $category['label'] ?? Str::headline($key),
            'description' => $category['description'] ?? null,
            'icon' => $iconMap[$category['icon'] ?? ''] ?? 'wifi',
            'badge' => $badgeMap[$key] ?? null,
            'href' => $href,
        ];
    })->values();

    // Hero slides. Both images ship with the application.
    $heroSlides = [
        ['src' => asset('images/storefront/hero-team.jpg'), 'alt' => 'The XTRA4U team delivering digital services in Ghana'],
        ['src' => asset('images/storefront/hero-services.jpg'), 'alt' => 'XTRA4U services and the mobile money networks accepted at checkout'],
    ];

    $paymentNetworks = [
        ['src' => asset('images/storefront/pay-mtn-momo.png'), 'alt' => 'MTN Mobile Money', 'bg' => '#1B4F72'],
        ['src' => asset('images/storefront/pay-telecel-cash.jpg'), 'alt' => 'Telecel Cash', 'bg' => '#ffffff'],
        ['src' => asset('images/storefront/pay-airteltigo-money.png'), 'alt' => 'AirtelTigo Money', 'bg' => '#ffffff'],
    ];
@endphp

@section('site-header')
    <x-storefront.header :shop-url="$shopUrl" :show-vendor-links="true" />
@endsection

@section('site-footer')
    <x-storefront.footer :shop-url="$shopUrl" :show-vendor-links="true" />
@endsection

@section('content')
{{-- The header is fixed at 64px tall; offset the page beneath it. --}}
<div class="x4-page" style="padding-top: 64px;">

    {{-- ============================================================
         Hero
         ============================================================ --}}
    <section class="relative overflow-hidden" style="background: #fff; padding-top: 64px;">
        <div class="x4-hero-wash absolute inset-0" aria-hidden="true" style="pointer-events: none;"></div>

        <div class="relative max-w-6xl mx-auto px-5">
            <div class="grid lg:grid-cols-2 gap-10 lg:gap-14 items-center pb-12 lg:pb-14">

                {{-- Copy --}}
                <div class="order-2 lg:order-1 text-center lg:text-left">
                    <x-storefront.reveal from="up">
                        <span
                            class="inline-flex items-center gap-2 mb-5"
                            style="background-color: var(--x4-violet-soft); border-radius: var(--x4-r-pill); padding: 5px 14px;"
                        >
                            <span aria-hidden="true" style="width: 6px; height: 6px; border-radius: 9999px; background-color: var(--x4-violet); flex-shrink: 0;"></span>
                            <span style="font-size: 10px; font-weight: 500; color: var(--x4-violet); letter-spacing: 0.08em; text-transform: uppercase;">
                                Ghana's Digital Services Marketplace
                            </span>
                        </span>

                        <h1 class="x4-display-xxl mb-5 lg:mb-6" style="color: var(--x4-ink-strong);">
                            Your Gateway to <span style="color: var(--x4-violet);">Digital Services</span>
                        </h1>

                        <p class="x4-body-lg mb-7 mx-auto lg:mx-0" style="color: var(--x4-ink-body); line-height: 1.65; max-width: 460px;">
                            Connect with verified vendors across Ghana. Secure transactions, reliable services,
                            and seamless digital experiences all in one place.
                        </p>

                        <div class="flex flex-wrap justify-center lg:justify-start gap-3 mb-7">
                            <x-storefront.btn :href="$shopUrl" variant="primary" hero>
                                Buy Now
                                <x-storefront.icon name="arrow" class="w-4 h-4" />
                            </x-storefront.btn>

                            <x-storefront.btn :href="route('order.status')" variant="outline" hero>
                                Order Status
                            </x-storefront.btn>
                        </div>

                        <ul class="flex flex-col items-center lg:items-start gap-2.5">
                            @foreach (['Verified Vendors', 'Mobile Money Payments', 'Under 5 minutes'] as $point)
                                <li class="flex items-center gap-2.5" style="font-size: 14px; color: var(--x4-ink-body);">
                                    <span
                                        aria-hidden="true"
                                        class="flex items-center justify-center flex-shrink-0"
                                        style="width: 20px; height: 20px; border-radius: 9999px; background-color: var(--x4-violet-soft);"
                                    >
                                        <x-storefront.icon name="check" class="w-3 h-3" style="color: var(--x4-violet);" />
                                    </span>
                                    {{ $point }}
                                </li>
                            @endforeach
                        </ul>
                    </x-storefront.reveal>
                </div>

                {{-- Image carousel --}}
                <div
                    class="order-1 lg:order-2 relative"
                    x-data="x4Hero({{ count($heroSlides) }})"
                    @mouseenter="pause()"
                    @mouseleave="resume()"
                    @focusin="pause()"
                    @focusout="resume()"
                    role="group"
                    aria-roledescription="carousel"
                    aria-label="XTRA4U highlights"
                >
                    <div class="x4-slider">
                        @foreach ($heroSlides as $i => $slide)
                            <img
                                src="{{ $slide['src'] }}"
                                alt="{{ $slide['alt'] }}"
                                width="1100"
                                height="619"
                                @if ($i === 0) fetchpriority="high" @endif
                                {{-- Object syntax, not a string: it toggles `is-active` by name,
                                     so Alpine can also strip the static one off the first slide.
                                     A string binding only removes classes it previously added. --}}
                                class="x4-slide{{ $i === 0 ? ' is-active' : '' }}"
                                :class="{ 'is-active': active === {{ $i }} }"
                                :aria-hidden="active === {{ $i }} ? 'false' : 'true'"
                                @if ($i > 0) aria-hidden="true" @endif
                            >
                        @endforeach

                        <div
                            aria-hidden="true"
                            class="absolute inset-0"
                            style="background: linear-gradient(to top, rgba(17,24,39,0.15) 0%, transparent 40%); pointer-events: none;"
                        ></div>

                        <div
                            class="x4-float absolute flex items-center gap-2"
                            style="top: 14px; right: 14px; background-color: var(--x4-violet); border-radius: 10px; padding: 6px 13px; box-shadow: 0 4px 14px rgba(91,61,245,0.35);"
                        >
                            <span class="x4-pulse-dot" aria-hidden="true" style="width: 7px; height: 7px; border-radius: 9999px; background-color: #4ade80;"></span>
                            <span style="color: #fff; font-size: 12px; font-weight: 400;">Platform Live</span>
                        </div>

                        @if (count($heroSlides) > 1)
                            <div class="absolute flex items-center" style="bottom: 8px; left: 50%; transform: translateX(-50%);">
                                @foreach ($heroSlides as $i => $slide)
                                    <button
                                        type="button"
                                        class="x4-slider-dot"
                                        @click="go({{ $i }})"
                                        :aria-current="active === {{ $i }} ? 'true' : 'false'"
                                        @if ($i === 0) aria-current="true" @endif
                                    >
                                        <span class="sr-only">Show image {{ $i + 1 }} of {{ count($heroSlides) }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Trust strip --}}
        <div style="background-color: var(--x4-brand-dark);">
            <div class="max-w-6xl mx-auto px-5">
                <div class="grid grid-cols-2 lg:grid-cols-4" style="border-top: 1px solid rgba(255,255,255,0.06);">
                    @foreach ([
                        ['icon' => 'shield', 'title' => 'Secure & Reliable', 'desc' => 'Your transactions are safe with us.'],
                        ['icon' => 'zap', 'title' => 'Instant Delivery', 'desc' => 'Get what you need, instantly.'],
                        ['icon' => 'phone', 'title' => '24/7 Support', 'desc' => "We're here for you, anytime."],
                        ['icon' => 'star', 'title' => 'Trusted by Thousands', 'desc' => 'Join thousands of satisfied customers.'],
                    ] as $i => $item)
                        <x-storefront.reveal from="up" :delay="$i * 90" class="flex">
                            <div class="flex items-center gap-3 py-5 px-4">
                                <span
                                    aria-hidden="true"
                                    class="flex items-center justify-center flex-shrink-0"
                                    style="width: 38px; height: 38px; border-radius: 9999px; border: 1.5px solid rgba(255,255,255,0.22); color: rgba(255,255,255,0.85);"
                                >
                                    <x-storefront.icon :name="$item['icon']" class="w-4 h-4" />
                                </span>
                                <div>
                                    <p style="color: #fff; font-size: 13px; font-weight: 400; line-height: 1.3;">{{ $item['title'] }}</p>
                                    <p style="color: rgba(255,255,255,0.5); font-size: 11px; margin-top: 2px;">{{ $item['desc'] }}</p>
                                </div>
                            </div>
                        </x-storefront.reveal>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{-- ============================================================
         Accepted payment networks + platform stats
         ============================================================ --}}
    <section style="background-color: var(--x4-canvas); border-top: 1px solid var(--x4-hairline); border-bottom: 1px solid var(--x4-hairline);">
        <div class="max-w-6xl mx-auto px-5 py-4 flex flex-wrap items-center justify-between gap-4">
            <p class="x4-caption" style="color: var(--x4-ink-mute);">Accepted payment networks:</p>

            <div class="x4-marquee-mask" style="flex: 1 1 160px; min-width: 0;">
                <div class="x4-marquee">
                    {{-- Tripled so the loop is seamless; the copies are decorative. --}}
                    @for ($pass = 0; $pass < 3; $pass++)
                        @foreach ($paymentNetworks as $network)
                            <div
                                class="flex items-center justify-center flex-shrink-0"
                                style="border-radius: 10px; overflow: hidden; background-color: {{ $network['bg'] }}; border: 1px solid var(--x4-hairline); width: 120px; height: 52px; box-shadow: var(--x4-shadow-1);"
                                @if ($pass > 0) aria-hidden="true" @endif
                            >
                                <img
                                    src="{{ $network['src'] }}"
                                    alt="{{ $pass === 0 ? $network['alt'] : '' }}"
                                    loading="lazy"
                                    style="width: 100%; height: 100%; object-fit: contain; padding: 6px 10px;"
                                >
                            </div>
                        @endforeach
                    @endfor
                </div>
            </div>

            <div class="hidden md:flex items-center gap-6">
                @foreach ([
                    ['value' => 500, 'suffix' => '+', 'decimals' => 0, 'label' => 'Verified Vendors'],
                    ['value' => 10, 'suffix' => 'K+', 'decimals' => 0, 'label' => 'Transactions'],
                    ['value' => 99.9, 'suffix' => '%', 'decimals' => 1, 'label' => 'Uptime'],
                ] as $stat)
                    <div class="text-right">
                        <p class="x4-tnum" style="font-size: 18px; color: var(--x4-ink); font-weight: 300; line-height: 1;">
                            <x-storefront.stat
                                :value="$stat['value']"
                                :decimals="$stat['decimals']"
                                :suffix="$stat['suffix']"
                            />
                        </p>
                        <p class="x4-micro-cap" style="color: var(--x4-ink-mute); margin-top: 2px;">{{ $stat['label'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ============================================================
         Services
         ============================================================ --}}
    <section id="services" style="background-color: var(--x4-canvas-soft); padding: 72px 0;">
        <div class="max-w-6xl mx-auto px-5">
            <x-storefront.reveal class="max-w-xl mb-10">
                <x-storefront.eyebrow>Services</x-storefront.eyebrow>

                <h2 class="x4-display-xl mt-4 mb-3" style="color: var(--x4-ink);">
                    Find the Digital Service <span style="color: var(--x4-violet);">You Need</span>
                </h2>

                <p class="x4-body-lg" style="color: var(--x4-ink-sec);">
                    Discover services from verified vendors all delivered digitally, all backed by secure payments.
                </p>
            </x-storefront.reveal>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                @foreach ($serviceCards as $i => $card)
                    <x-storefront.reveal :delay="$i * 75" class="flex flex-col">
                        <x-storefront.service-card
                            :name="$card['name']"
                            :description="$card['description']"
                            :icon="$card['icon']"
                            :badge="$card['badge']"
                            :href="$card['href']"
                        />
                    </x-storefront.reveal>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ============================================================
         Featured service: results checker PINs
         ============================================================ --}}
    <section style="background-color: var(--x4-canvas); border-top: 1px solid var(--x4-hairline); padding: 0 0 80px;">
        <div class="max-w-6xl mx-auto px-5">
            <div class="relative mb-8 sm:mb-12 overflow-hidden" style="border-radius: 0 0 20px 20px; height: clamp(180px, 40vw, 300px);">
                <img
                    src="{{ asset('images/storefront/feature-results.jpg') }}"
                    alt="Students in Ghana checking their exam results"
                    loading="lazy"
                    class="w-full h-full object-cover"
                    style="object-position: center 30%;"
                >
                <div aria-hidden="true" class="absolute inset-0" style="background: linear-gradient(to bottom, rgba(28,30,84,0.2), rgba(28,30,84,0.72));"></div>

                <div class="absolute bottom-0 left-0 right-0 p-6 sm:p-8 flex items-end justify-between gap-6">
                    <div>
                        <x-storefront.eyebrow class="mb-3">Featured Service</x-storefront.eyebrow>
                        <h2 class="x4-display-lg" style="color: #fff;">
                            Results Checker PINs, <span style="color: var(--x4-primary-sub);">Delivered Instantly</span>
                        </h2>
                    </div>

                    <div class="hidden md:block text-right flex-shrink-0">
                        <p class="x4-tnum" style="color: #fff; font-size: 32px; font-weight: 300; letter-spacing: -0.64px;">
                            WAEC &middot; BECE &middot; NOVDEC
                        </p>
                        <p style="color: rgba(255,255,255,0.55); font-size: 13px;">All major exam boards covered</p>
                    </div>
                </div>
            </div>

            <div class="grid md:grid-cols-2 gap-12 items-start">
                <x-storefront.reveal from="left">
                    <p class="x4-body-lg mb-6" style="color: var(--x4-ink-mute);">
                        Buy WAEC, BECE, and NOVDEC results checker PINs securely. PINs are stored safely
                        &mdash; retrieve yours anytime you need it.
                    </p>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-8">
                        @foreach ([
                            ['label' => 'Instant Delivery', 'desc' => 'PINs sent immediately after payment'],
                            ['label' => 'Securely Stored', 'desc' => 'Your PIN saved for later retrieval'],
                            ['label' => 'Retrieve Anytime', 'desc' => 'Access your PIN whenever you need it'],
                            ['label' => 'Buy in Bulk', 'desc' => 'For schools, caf&eacute;s, and agents'],
                        ] as $benefit)
                            <div class="flex items-start gap-2.5">
                                <x-storefront.icon name="check" class="w-4 h-4 flex-shrink-0 mt-0.5" style="color: var(--x4-primary);" />
                                <div>
                                    <p style="font-size: 14px; font-weight: 400; color: var(--x4-ink);">{{ $benefit['label'] }}</p>
                                    <p class="x4-caption mt-0.5" style="color: var(--x4-ink-mute);">{!! $benefit['desc'] !!}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <x-storefront.btn :href="route('result-checkers.entry')" variant="primary">
                            Buy a Results Checker
                        </x-storefront.btn>
                        <x-storefront.btn :href="route('result-checkers.status')" variant="outline">
                            Retrieve My PIN
                        </x-storefront.btn>
                    </div>
                </x-storefront.reveal>

                <x-storefront.reveal from="right" :delay="100">
                    {{-- Illustration of a completed order. Hidden from
                         assistive tech so the sample values are never read
                         out as if they were the visitor's own order. --}}
                    <div style="background-color: var(--x4-canvas-cream); border-radius: var(--x4-r-xl); padding: 32px; box-shadow: var(--x4-shadow-1);">
                        <p class="sr-only">Illustration of a completed results checker order.</p>

                        <div aria-hidden="true">
                            <div class="flex items-center justify-between mb-5">
                                <span style="font-size: 14px; font-weight: 400; color: var(--x4-ink);">Results Checker PIN</span>
                                <x-storefront.eyebrow>WAEC 2025</x-storefront.eyebrow>
                            </div>

                            <div style="background-color: var(--x4-canvas); border-radius: 10px; padding: 20px 16px; text-align: center; margin-bottom: 16px; border: 1px solid var(--x4-hairline);">
                                <p class="x4-caption mb-2" style="color: var(--x4-ink-mute);">Your PIN</p>
                                <p class="x4-tnum" style="font-size: 26px; letter-spacing: 0.14em; color: var(--x4-ink);">&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;</p>
                                <p style="font-size: 11px; color: var(--x4-ink-mute); margin-top: 6px;">Securely stored &mdash; retrieve anytime</p>
                            </div>

                            @foreach ([
                                ['k' => 'Exam Board', 'v' => 'WAEC 2025', 'accent' => false],
                                ['k' => 'Status', 'v' => 'Delivered', 'accent' => true],
                                ['k' => 'Order ID', 'v' => 'XTR-00419', 'accent' => false],
                                ['k' => 'Payment', 'v' => 'MTN MoMo', 'accent' => false],
                            ] as $row)
                                <div class="flex justify-between py-2" style="border-bottom: 1px solid rgba(0,0,0,0.06);">
                                    <span class="x4-caption" style="color: var(--x4-ink-mute);">{{ $row['k'] }}</span>
                                    <span class="x4-caption x4-tnum" style="color: {{ $row['accent'] ? '#16a34a' : 'var(--x4-ink)' }};">{{ $row['v'] }}</span>
                                </div>
                            @endforeach
                        </div>

                        <x-storefront.btn :href="route('result-checkers.status')" variant="primary" class="w-full mt-5">
                            Retrieve My PIN
                        </x-storefront.btn>
                    </div>
                </x-storefront.reveal>
            </div>
        </div>
    </section>

    {{-- ============================================================
         How it works
         ============================================================ --}}
    <section style="background-color: var(--x4-canvas-soft); padding: 80px 0;">
        <div class="max-w-6xl mx-auto px-5">
            <x-storefront.reveal class="text-center mb-14">
                <x-storefront.eyebrow>How it works</x-storefront.eyebrow>
                <h2 class="x4-display-xl mt-4 mb-3" style="color: var(--x4-ink);">Three steps. Under 5 minutes.</h2>
                <p class="x4-body-lg" style="color: var(--x4-ink-sec);">No complicated process. Just find, pay, and receive.</p>
            </x-storefront.reveal>

            <div class="relative grid md:grid-cols-3 gap-6">
                <div
                    aria-hidden="true"
                    class="hidden md:block absolute"
                    style="top: 20px; left: calc(16.67% + 40px); right: calc(16.67% + 40px); height: 1px; background-color: var(--x4-hairline);"
                ></div>

                @foreach ([
                    ['n' => '01', 'title' => 'Choose Your Service', 'body' => 'Browse our marketplace, pick a vendor, and select the service or product you need.'],
                    ['n' => '02', 'title' => 'Make Payment', 'body' => 'Pay securely via Mobile Money — MTN, Telecel, or AirtelTigo. Your payment is protected.'],
                    ['n' => '03', 'title' => 'Get It Instantly', 'body' => 'Receive your service within minutes. Track order status and get SMS/email notifications.'],
                ] as $i => $step)
                    <x-storefront.reveal :delay="$i * 110">
                        <div style="background-color: var(--x4-canvas); border: 1px solid var(--x4-hairline); border-radius: var(--x4-r-lg); padding: 28px; box-shadow: var(--x4-shadow-1); height: 100%;">
                            <div
                                class="x4-tnum w-10 h-10 flex items-center justify-center mb-5 relative z-10"
                                style="border-radius: 8px; font-size: 14px; font-weight: 400;
                                    background-color: {{ $i === 0 ? 'var(--x4-primary)' : 'var(--x4-canvas-soft)' }};
                                    color: {{ $i === 0 ? '#fff' : 'var(--x4-ink-mute)' }};
                                    {{ $i === 0 ? 'box-shadow: 0 0 0 4px var(--x4-primary-sub);' : '' }}"
                            >{{ $step['n'] }}</div>

                            <h3 class="x4-heading-md mb-2" style="color: var(--x4-ink);">{{ $step['title'] }}</h3>
                            <p class="x4-body-md" style="color: var(--x4-ink-sec);">{{ $step['body'] }}</p>
                        </div>
                    </x-storefront.reveal>
                @endforeach
            </div>

            <x-storefront.reveal :delay="330" class="text-center mt-10">
                <x-storefront.btn :href="$shopUrl" variant="primary">
                    Get Started
                    <x-storefront.icon name="arrow" class="w-4 h-4" />
                </x-storefront.btn>
            </x-storefront.reveal>
        </div>
    </section>

    {{-- ============================================================
         Why XTRA4U
         ============================================================ --}}
    <section style="background-color: var(--x4-canvas); padding: 80px 0 96px;">
        <div class="max-w-6xl mx-auto px-5 grid lg:grid-cols-2 gap-14 lg:gap-14 items-center">
            <x-storefront.reveal from="left" class="relative">
                <div class="relative">
                    <div style="border-radius: var(--x4-r-xl); overflow: hidden; height: clamp(280px, 55vw, 460px); background-color: var(--x4-canvas-soft); box-shadow: var(--x4-shadow-2);">
                        <img
                            src="{{ asset('images/storefront/why-customer.jpg') }}"
                            alt="A customer buying digital services on XTRA4U"
                            loading="lazy"
                            class="w-full h-full object-cover"
                            style="object-position: center 18%;"
                        >
                        <div aria-hidden="true" class="absolute inset-0" style="background: linear-gradient(to top, rgba(28,30,84,0.4) 0%, transparent 60%);"></div>
                    </div>

                    <div
                        class="absolute right-0 lg:-right-6 -bottom-4 lg:-bottom-6 px-4 py-3 sm:px-5 sm:py-4"
                        style="background-color: var(--x4-canvas); border-radius: 14px; box-shadow: var(--x4-shadow-2); border: 1px solid var(--x4-hairline);"
                    >
                        <p class="x4-micro-cap mb-2" style="color: var(--x4-ink-mute);">Platform Health</p>

                        @foreach ([
                            ['label' => 'Uptime', 'value' => 99.9, 'suffix' => '%', 'prefix' => '', 'decimals' => 1],
                            ['label' => 'Avg. Delivery', 'value' => 5, 'suffix' => ' min', 'prefix' => '< ', 'decimals' => 0],
                            ['label' => 'Vendor Rating', 'value' => 4.8, 'suffix' => ' / 5', 'prefix' => '', 'decimals' => 1],
                        ] as $metric)
                            <div class="flex justify-between gap-8 py-1">
                                <span class="x4-caption" style="color: var(--x4-ink-mute);">{{ $metric['label'] }}</span>
                                <span class="x4-tnum x4-caption" style="color: var(--x4-ink); font-weight: 400;">
                                    <x-storefront.stat
                                        :value="$metric['value']"
                                        :decimals="$metric['decimals']"
                                        :prefix="$metric['prefix']"
                                        :suffix="$metric['suffix']"
                                    />
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </x-storefront.reveal>

            <x-storefront.reveal from="right" :delay="80">
                <x-storefront.eyebrow>Why XTRA4U</x-storefront.eyebrow>

                <h2 class="x4-display-xl mt-4 mb-4" style="color: var(--x4-ink);">
                    Built for speed, <span style="color: var(--x4-primary);">backed by trust.</span>
                </h2>

                <p class="x4-body-lg mb-8" style="color: var(--x4-ink-mute);">
                    XTRA4U makes buying digital services in Ghana fast, safe, and simple. Every vendor is verified.
                    Every transaction is protected. From data bundles to exam PINs; find it, pay, and receive
                    it in minutes.
                </p>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    @foreach ([
                        ['icon' => 'shield', 'title' => 'Verified Vendors', 'body' => 'Every vendor is ID-verified and quality-assured before listing on XTRA4U.'],
                        ['icon' => 'check', 'title' => 'Secure Transactions', 'body' => 'Encrypted payments with fraud protection. Your money is safe at every step.'],
                        ['icon' => 'zap', 'title' => 'Lightning Fast', 'body' => 'Most orders complete in under 5 minutes. Instant processing and notifications.'],
                        ['icon' => 'clock', 'title' => '24/7 Support', 'body' => 'Support available around the clock via WhatsApp, email, and the platform.'],
                    ] as $i => $feature)
                        <x-storefront.reveal :delay="$i * 70">
                            <div style="background-color: var(--x4-canvas-soft); border: 1px solid var(--x4-hairline); border-radius: 10px; padding: 18px; height: 100%;">
                                <x-storefront.icon :name="$feature['icon']" class="w-5 h-5 mb-2" style="color: var(--x4-primary);" />
                                <h3 style="font-size: 14px; font-weight: 400; color: var(--x4-ink); margin-bottom: 4px;">{{ $feature['title'] }}</h3>
                                <p style="font-size: 13px; color: var(--x4-ink-sec); line-height: 1.4;">{{ $feature['body'] }}</p>
                            </div>
                        </x-storefront.reveal>
                    @endforeach
                </div>
            </x-storefront.reveal>
        </div>
    </section>

    {{-- ============================================================
         Vendor recruitment
         ============================================================ --}}
    <section id="vendors" style="background-color: var(--x4-canvas-soft); padding: 80px 0;">
        <div class="max-w-6xl mx-auto px-5">
            <div class="grid lg:grid-cols-2 overflow-hidden" style="border-radius: var(--x4-r-xl); box-shadow: var(--x4-shadow-2);">
                <x-storefront.reveal from="left">
                    <div class="flex flex-col justify-center sm:p-12 h-full" style="background-color: var(--x4-brand-dark); padding: 36px 28px;">
                        <span
                            class="x4-micro-cap self-start mb-5 px-2 py-1"
                            style="background-color: rgba(83,58,253,0.3); color: var(--x4-primary-sub); border-radius: var(--x4-r-pill);"
                        >For Vendors</span>

                        <h2 class="x4-display-xl mb-4" style="color: #fff;">Have a Digital Service to Sell?</h2>

                        <p class="x4-body-lg mb-8" style="color: rgba(255,255,255,0.6);">
                            Join XTRA4U, reach customers across Ghana, and manage your services from one platform.
                            Get paid through Mobile Money with no complications.
                        </p>

                        <div class="grid grid-cols-3 gap-4 mb-8 pb-8" style="border-bottom: 1px solid rgba(255,255,255,0.08);">
                            @foreach ([
                                ['value' => 500, 'prefix' => '', 'suffix' => '+', 'decimals' => 0, 'label' => 'Active Vendors'],
                                ['value' => 1, 'prefix' => 'GH₵', 'suffix' => 'M+', 'decimals' => 0, 'label' => 'Paid Out'],
                                ['value' => 24, 'prefix' => '', 'suffix' => '/7', 'decimals' => 0, 'label' => 'Support'],
                            ] as $stat)
                                <div>
                                    <p class="x4-tnum" style="font-size: 20px; color: #fff; font-weight: 300; letter-spacing: -0.4px;">
                                        <x-storefront.stat
                                            :value="$stat['value']"
                                            :decimals="$stat['decimals']"
                                            :prefix="$stat['prefix']"
                                            :suffix="$stat['suffix']"
                                        />
                                    </p>
                                    <p style="font-size: 11px; color: rgba(255,255,255,0.45); margin-top: 2px;">{{ $stat['label'] }}</p>
                                </div>
                            @endforeach
                        </div>

                        <div class="flex flex-wrap gap-3">
                            <x-storefront.btn :href="route('vendor.request.form')" variant="primary">
                                Become a Vendor
                                <x-storefront.icon name="arrow" class="w-4 h-4" />
                            </x-storefront.btn>
                            <x-storefront.btn :href="route('vendor.login.form')" variant="ghost">
                                Vendor Login
                            </x-storefront.btn>
                        </div>
                    </div>
                </x-storefront.reveal>

                <x-storefront.reveal from="right" :delay="100">
                    {{-- Stacked on small screens this row is short, so the crop
                         is pulled down from the very top to keep the subject
                         in frame at every width. --}}
                    <div class="relative min-h-80 lg:min-h-full h-full" style="background-color: var(--x4-canvas-soft);">
                        <img
                            src="{{ asset('images/storefront/vendor-person.jpg') }}"
                            alt="An XTRA4U vendor managing their storefront"
                            loading="lazy"
                            class="w-full h-full object-cover absolute inset-0"
                            style="object-position: center 22%;"
                        >
                        <div aria-hidden="true" class="absolute inset-0" style="background: linear-gradient(to right, rgba(28,30,84,0.35), transparent);"></div>

                        {{-- Illustrative dashboard preview. --}}
                        <div
                            class="absolute bottom-6 right-6 left-6 px-4 py-4"
                            style="background-color: rgba(255,255,255,0.92); border-radius: var(--x4-r-lg); backdrop-filter: blur(12px); border: 1px solid rgba(255,255,255,0.6);"
                        >
                            <p class="sr-only">Illustration of the vendor dashboard.</p>
                            <div aria-hidden="true">
                                <div class="flex items-center justify-between mb-3">
                                    <span style="font-size: 13px; font-weight: 400; color: var(--x4-ink);">Vendor Dashboard</span>
                                    <span class="x4-micro-cap px-2 py-0.5" style="background-color: #dcfce7; color: #166534; border-radius: var(--x4-r-pill);">Active</span>
                                </div>

                                @foreach ([['Orders Today', '24'], ['Revenue (GH₵)', '1,840'], ['Rating', '4.9 / 5.0']] as $row)
                                    <div class="flex justify-between py-1.5" style="border-bottom: 1px solid var(--x4-hairline);">
                                        <span class="x4-caption" style="color: var(--x4-ink-mute);">{{ $row[0] }}</span>
                                        <span class="x4-tnum x4-caption" style="color: var(--x4-ink); font-weight: 400;">{{ $row[1] }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </x-storefront.reveal>
            </div>
        </div>
    </section>

    {{-- ============================================================
         Closing call to action
         ============================================================ --}}
    <section class="relative overflow-hidden" style="padding: 96px 0;">
        <div class="x4-aurora absolute inset-0" aria-hidden="true" style="opacity: 0.65;"></div>

        <div class="relative max-w-6xl mx-auto px-5 grid lg:grid-cols-2 gap-10 lg:gap-16 items-center">
            <x-storefront.reveal from="up">
                <h2 class="x4-display-xl mb-4" style="color: var(--x4-ink);">Ready to Get Started?</h2>

                <p class="x4-body-lg mb-8" style="color: var(--x4-ink-sec);">
                    Find the service you need from trusted vendors on XTRA4U. Quick, secure, and delivered within minutes.
                </p>

                <div class="flex flex-wrap gap-3 mb-7">
                    <x-storefront.btn :href="$shopUrl" variant="primary">
                        Start Shopping
                        <x-storefront.icon name="arrow" class="w-4 h-4" />
                    </x-storefront.btn>
                    <x-storefront.btn :href="route('vendor.request.form')" variant="outline">
                        Become a Vendor
                    </x-storefront.btn>
                </div>

                <p class="flex items-center gap-2">
                    <span aria-hidden="true" class="flex items-center gap-0.5">
                        @for ($i = 0; $i < 5; $i++)
                            <x-storefront.icon name="star" class="w-4 h-4" style="color: #f59e0b;" />
                        @endfor
                    </span>
                    <span class="x4-caption ml-1" style="color: var(--x4-ink-mute);">Trusted by thousands across Ghana</span>
                </p>
            </x-storefront.reveal>

            <x-storefront.reveal from="right" :delay="120" class="hidden lg:block">
                <div class="relative" style="border-radius: var(--x4-r-xl); overflow: hidden; height: 340px; box-shadow: var(--x4-shadow-3); background-color: var(--x4-canvas-soft);">
                    <img
                        src="{{ asset('images/storefront/ghana-street.jpg') }}"
                        alt="A busy street market in Ghana"
                        loading="lazy"
                        class="w-full h-full object-cover"
                    >
                    <div aria-hidden="true" class="absolute inset-0" style="background: linear-gradient(135deg, rgba(83,58,253,0.15), transparent);"></div>

                    <div class="absolute top-4 left-4">
                        <x-storefront.logo :on-dark="true" />
                    </div>

                    <div
                        class="absolute bottom-4 left-4 right-4 text-center py-3 px-4"
                        style="background-color: rgba(255,255,255,0.88); border-radius: 10px; backdrop-filter: blur(8px);"
                    >
                        <p style="font-size: 13px; font-weight: 400; color: var(--x4-ink);">
                            Proudly serving customers across Ghana
                        </p>
                    </div>
                </div>
            </x-storefront.reveal>
        </div>
    </section>
</div>
@endsection

