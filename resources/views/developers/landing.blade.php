{{--
    Public Developer API page. Same storefront shell and design language as /about and the homepage; only the call to
    action changes with who is looking ($state: guest | pending | approved). Nothing here is vendor- or customer-specific.
--}}
@extends('layouts.developers')
@section('title', 'XTRA4U Developer API - for approved vendors')
@section('description', 'Sell your XTRA4U products from your own website or app with the XTRA4U Developer API: sandbox, signed webhooks and full documentation for approved vendors.')

@php
    $capabilities = [
        ['document', 'Your catalogue, live prices', 'Browse <b>your own</b> products and current prices, then request a price quote for a recipient number.'],
        ['receipt', 'Orders and payments', 'Place an order from a quote and start its payment. Follow order and payment status, or let us tell you with <b>signed webhooks</b>.'],
        ['users', 'Your store, your earnings', 'API orders are <b>your store\'s orders</b>: pricing, earnings and commissions are exactly the same as on your storefront. The API adds no fees or markups.'],
        ['shield', 'Only your data', 'Your applications can only ever see and sell <b>your</b> products. No other vendor\'s data is reachable.'],
    ];
    $steps = [
        ['Apply as a vendor', 'Apply on XTRA4U through the normal <a class="underline" style="color: var(--x4-violet);" href="'.route('vendor.request.form').'">vendor application</a>.'],
        ['Get approved', 'An XTRA4U administrator reviews and approves your vendor account.'],
        ['Sign in', 'Sign in with your vendor account and open the Developer Portal here. There is no separate developer login.'],
        ['Create a key', 'Create an application and generate an API key. The full key is shown once, so store it safely.'],
    ];
    $flow = [
        ['GET', '/api/v1/products', 'list your offers and prices.'],
        ['POST', '/api/v1/quotes', 'freeze a price for a recipient number.'],
        ['POST', '/api/v1/orders', 'create the order from the quote (send an <code>Idempotency-Key</code>).'],
        ['POST', '/api/v1/orders/{id}/payments', 'start payment and send the customer to the returned link.'],
        ['GET', '/api/v1/orders/{id}/status', 'poll the result, or receive signed webhooks instead.'],
    ];
@endphp

@section('page')
    {{-- ============================ Hero ============================ --}}
    <section class="relative overflow-hidden" style="background: #fff;">
        <div class="x4-hero-wash absolute inset-0" aria-hidden="true" style="pointer-events: none;"></div>

        <div class="relative max-w-6xl mx-auto px-5 grid grid-cols-1 lg:grid-cols-2 gap-10 items-center" style="padding-top: 64px; padding-bottom: 64px;">
            <div class="text-center lg:text-left">
                <x-storefront.eyebrow>Developer API</x-storefront.eyebrow>
                <h1 class="x4-display-xxl mt-4 mb-4" style="color: var(--x4-ink-strong);">The XTRA4U API for vendors</h1>
                <p class="x4-body-lg mb-8" style="color: var(--x4-ink-body);">
                    Sell your XTRA4U products from your own website, mobile app or platform. The API is open for anyone to read about, and available to <b>approved XTRA4U vendors</b>.
                </p>

                @if($state === 'approved')
                    <div class="flex flex-wrap gap-3 justify-center lg:justify-start">
                        <x-storefront.btn :href="route('developers.dashboard')" variant="primary" :hero="true">Open your Developer Portal</x-storefront.btn>
                        <x-storefront.btn :href="route('developers.docs.index')" variant="outline" :hero="true">Read the API documentation</x-storefront.btn>
                    </div>
                @elseif($state === 'pending')
                    <div class="x4-panel inline-block text-left" style="padding: 16px 20px; max-width: 32rem;" role="status">
                        <p class="x4-body-md" style="color: var(--x4-ink);">Your vendor account is awaiting approval. API access switches on as soon as an administrator approves it.</p>
                    </div>
                @else
                    <div class="flex flex-wrap gap-3 justify-center lg:justify-start">
                        <x-storefront.btn :href="route('vendor.request.form')" variant="primary" :hero="true">Become a vendor</x-storefront.btn>
                        <x-storefront.btn :href="route('vendor.login.form')" variant="outline" :hero="true">Vendor sign in</x-storefront.btn>
                    </div>
                @endif
            </div>

            {{-- Authentication example --}}
            <div class="min-w-0">
                <div style="background: var(--x4-brand-dark, #1c1e54); border-radius: var(--x4-r-xl); box-shadow: var(--x4-shadow-2); overflow: hidden;">
                    <div class="flex items-center gap-2" style="padding: 12px 18px; border-bottom: 1px solid rgba(255,255,255,0.12);">
                        <span aria-hidden="true" style="width: 10px; height: 10px; border-radius: 9999px; background: rgba(255,255,255,0.28);"></span>
                        <span aria-hidden="true" style="width: 10px; height: 10px; border-radius: 9999px; background: rgba(255,255,255,0.28);"></span>
                        <span aria-hidden="true" style="width: 10px; height: 10px; border-radius: 9999px; background: rgba(255,255,255,0.28);"></span>
                        <span class="x4-caption" style="margin-left: 8px; color: rgba(255,255,255,0.7);">Authentication</span>
                    </div>
                    <pre style="margin: 0; padding: 20px 22px; overflow-x: auto; color: #f3f4f6; font-size: 13px; line-height: 1.7; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;">curl {{ url('/api/v1/products') }} \
  -H "Authorization: Bearer xt_live_yourkey"</pre>
                </div>
                <p class="x4-caption mt-3" style="color: var(--x4-ink-mute);">Keys look like <code>xt_live_…</code> (production) or <code>xt_test_…</code> (test). Each key has scopes and an expiry, and requests are rate limited per application.</p>
            </div>
        </div>
    </section>

    {{-- ============================ What the API offers ============================ --}}
    <section style="background-color: var(--x4-canvas-soft); padding: 72px 0;">
        <div class="max-w-6xl mx-auto px-5">
            <x-storefront.reveal class="text-center mb-10">
                <x-storefront.eyebrow>What the API offers</x-storefront.eyebrow>
                <h2 class="x4-display-xl mt-4" style="color: var(--x4-ink);">Everything your store sells, from your own code</h2>
            </x-storefront.reveal>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                @foreach($capabilities as $i => [$icon, $title, $body])
                    <x-storefront.reveal :delay="$i * 80" class="flex">
                        <div class="x4-panel" style="padding: 26px; width: 100%;">
                            <div class="x4-service-icon mb-4"><x-storefront.icon :name="$icon" class="w-5 h-5" /></div>
                            <h3 class="x4-heading-md mb-2" style="color: var(--x4-ink);">{{ $title }}</h3>
                            <p class="x4-body-md" style="color: var(--x4-ink-sec);">{!! $body !!}</p>
                        </div>
                    </x-storefront.reveal>
                @endforeach
            </div>

            <div class="x4-panel mt-8" style="padding: 24px 28px;">
                <h3 class="x4-heading-md mb-3" style="color: var(--x4-ink);">Available services</h3>
                <ul class="flex flex-wrap gap-x-6 gap-y-2">
                    @foreach($services as $svc)
                        <li class="x4-body-md" style="color: var(--x4-ink-sec);">
                            <b style="color: var(--x4-ink);">{{ $svc['name'] }}</b>@if($svc['description']) &mdash; {{ $svc['description'] }}@endif
                            <span class="x4-caption" style="margin-left: 6px; padding: 2px 10px; border-radius: var(--x4-r-pill); background: {{ $svc['available'] ? 'var(--x4-violet-soft)' : 'var(--x4-canvas-soft)' }}; color: {{ $svc['available'] ? 'var(--x4-violet-deep)' : 'var(--x4-ink-mute)' }};">{{ $svc['available'] ? 'open' : 'currently closed' }}</span>
                        </li>
                    @endforeach
                </ul>
                <p class="x4-caption mt-3" style="color: var(--x4-ink-mute);">Other XTRA4U services are not part of API v1.</p>
            </div>
        </div>
    </section>

    {{-- ============================ How to get access ============================ --}}
    <section style="background-color: var(--x4-canvas); padding: 80px 0;">
        <div class="max-w-6xl mx-auto px-5">
            <x-storefront.reveal class="text-center mb-10">
                <x-storefront.eyebrow>Getting started</x-storefront.eyebrow>
                <h2 class="x4-display-xl mt-4" style="color: var(--x4-ink);">How to get access</h2>
            </x-storefront.reveal>

            <ol class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                @foreach($steps as $i => [$title, $body])
                    <li>
                        <x-storefront.reveal :delay="$i * 80" class="flex" style="height: 100%;">
                            <div style="background-color: var(--x4-canvas-soft); border: 1px solid var(--x4-hairline); border-radius: var(--x4-r-lg); padding: 28px 22px; width: 100%;">
                                <div class="mb-4 flex items-center justify-center" style="width: 40px; height: 40px; border-radius: 9999px; background-color: var(--x4-violet-soft); color: var(--x4-violet-deep); font-weight: 600;" aria-hidden="true">{{ $i + 1 }}</div>
                                <h3 class="x4-heading-md mb-2" style="color: var(--x4-ink);">{{ $title }}</h3>
                                <p class="x4-body-md" style="color: var(--x4-ink-sec);">{!! $body !!}</p>
                            </div>
                        </x-storefront.reveal>
                    </li>
                @endforeach
            </ol>
            <p class="x4-caption text-center mt-6" style="color: var(--x4-ink-mute);">Pending, rejected or suspended vendors cannot use the API. If your vendor account loses approval, your API keys are revoked and your webhooks disabled straight away.</p>
        </div>
    </section>

    {{-- ============================ Sandbox + webhooks ============================ --}}
    <section style="background-color: var(--x4-canvas-soft); padding: 80px 0;">
        <div class="max-w-6xl mx-auto px-5 grid grid-cols-1 lg:grid-cols-2 gap-6">
            <x-storefront.reveal class="flex">
                <div class="x4-panel" style="padding: 32px; width: 100%;">
                    <div class="x4-service-icon mb-5"><x-storefront.icon name="bolt" class="w-5 h-5" /></div>
                    <h2 class="x4-heading-md mb-3" style="color: var(--x4-ink); font-size: 22px;">Sandbox: build and test safely</h2>
                    <p class="x4-body-md" style="color: var(--x4-ink-sec);">Create a <b>test key</b> (<code>xt_test_…</code>) and the whole flow works against a simulated environment: catalog, quotes, orders, payments, status checks and webhooks. Nothing in the sandbox moves real money, delivers a real product or changes any wallet or commission. You choose whether each simulated payment and delivery succeeds or fails, so you can test every outcome before you switch to a live key. The URLs and request shapes are the same for test and live.</p>
                </div>
            </x-storefront.reveal>
            <x-storefront.reveal :delay="90" class="flex">
                <div class="x4-panel" style="padding: 32px; width: 100%;">
                    <div class="x4-service-icon mb-5"><x-storefront.icon name="zap" class="w-5 h-5" /></div>
                    <h2 class="x4-heading-md mb-3" style="color: var(--x4-ink); font-size: 22px;">Webhooks</h2>
                    <p class="x4-body-md" style="color: var(--x4-ink-sec);">Instead of polling, register an HTTPS endpoint in the portal and receive a signed POST when an order or payment changes (<code>order.created</code>, <code>order.processing</code>, <code>order.completed</code>, <code>order.failed</code>, <code>payment.pending</code>, <code>payment.succeeded</code>, <code>payment.failed</code>). Every delivery carries an HMAC-SHA256 signature you verify with your endpoint's signing secret, and failed deliveries are retried automatically.</p>
                </div>
            </x-storefront.reveal>
        </div>
    </section>

    {{-- ============================ Integration steps ============================ --}}
    <section style="background-color: var(--x4-canvas); padding: 80px 0;">
        <div class="max-w-4xl mx-auto px-5">
            <x-storefront.reveal class="text-center mb-10">
                <x-storefront.eyebrow>Integration steps</x-storefront.eyebrow>
                <h2 class="x4-display-xl mt-4" style="color: var(--x4-ink);">From catalogue to confirmed order</h2>
            </x-storefront.reveal>

            <ol class="x4-panel" style="padding: 8px 0;">
                @foreach($flow as $i => [$method, $path, $text])
                    <li class="flex items-start gap-4" style="padding: 16px 24px; {{ $i > 0 ? 'border-top: 1px solid var(--x4-hairline);' : '' }}">
                        <span class="shrink-0 flex items-center justify-center" style="width: 28px; height: 28px; border-radius: 9999px; background: var(--x4-violet-soft); color: var(--x4-violet-deep); font-size: 13px; font-weight: 600;" aria-hidden="true">{{ $i + 1 }}</span>
                        <p class="x4-body-md min-w-0" style="color: var(--x4-ink-sec); overflow-wrap: anywhere;">
                            <code style="font-weight: 600; color: var(--x4-ink);"><span style="color: var(--x4-violet-deep);">{{ $method }}</span> {{ $path }}</code> &mdash; {!! $text !!}
                        </p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- ============================ Documentation CTA ============================ --}}
    <section class="relative overflow-hidden" style="padding: 88px 0;">
        <div class="x4-aurora absolute inset-0" aria-hidden="true" style="opacity: 0.65;"></div>
        <div class="relative max-w-3xl mx-auto px-5 text-center">
            <x-storefront.reveal from="up">
                <h2 class="x4-display-xl mb-4" style="color: var(--x4-ink);">API documentation</h2>
                <p class="x4-body-lg mb-8" style="color: var(--x4-ink-sec);">The full reference covers the base URL, authentication, scopes, every endpoint with request and response examples, quotes and orders, payments, the sandbox, webhooks and signature verification, error codes and rate limits.</p>
                @if($state === 'approved')
                    <x-storefront.btn :href="route('developers.docs.index')" variant="primary" :hero="true">Read the API documentation</x-storefront.btn>
                @else
                    <p class="x4-body-md" style="color: var(--x4-ink-sec);">The documentation is part of the Developer Portal and opens as soon as your vendor account is approved and you <a class="underline" style="color: var(--x4-violet);" href="{{ route('vendor.login.form') }}">sign in</a>.</p>
                @endif
            </x-storefront.reveal>
        </div>
    </section>
@endsection
