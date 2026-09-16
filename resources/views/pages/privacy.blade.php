{{--
    pages/privacy.blade.php

    Static legal page (route: GET /privacy, closure-bound in web.php).
    Visual redesign only — every sentence of the policy text is preserved
    verbatim; only markup/classes changed.
--}}
@extends('layouts.app')

@section('title', 'Privacy Policy - XTRA4U')
@section('description', 'Privacy Policy for XTRA4U - Learn how we collect, use, and protect your personal information.')

{{-- Scope the storefront design system to this page only. --}}
@section('body-class', 'x4')

@php
    $mainStoreVendor = \App\Support\MainStore::vendor();
    $shopUrl = $mainStoreVendor
        ? route('storefront.vendor', ['vendor' => $mainStoreVendor->vendor_code])
        : route('checkout.show');
@endphp

@section('site-header')
    <x-storefront.header :shop-url="$shopUrl" />
@endsection

@section('site-footer')
    <x-storefront.footer :shop-url="$shopUrl" />
@endsection

@section('content')
<div class="x4-page" style="padding-top: 64px;">

    {{-- ============================================================
         Hero
         ============================================================ --}}
    <section class="relative overflow-hidden" style="background: #fff;">
        <div class="x4-hero-wash absolute inset-0" aria-hidden="true" style="pointer-events: none;"></div>

        <div class="relative max-w-3xl mx-auto px-5 text-center" style="padding-top: 56px; padding-bottom: 48px;">
            <x-storefront.reveal from="up">
                <div class="mx-auto mb-5 flex items-center justify-center" style="width: 60px; height: 60px; border-radius: var(--x4-r-lg); background-color: var(--x4-violet);">
                    <x-storefront.icon name="lock" class="w-6 h-6" style="color: #fff;" />
                </div>

                <h1 class="x4-display-xl mb-3" style="color: var(--x4-ink-strong);">Privacy Policy</h1>
                <p class="x4-caption" style="color: var(--x4-ink-mute);">Last updated: {{ date('F j, Y') }}</p>
            </x-storefront.reveal>
        </div>
    </section>

    {{-- ============================================================
         Content
         ============================================================ --}}
    <section style="background-color: var(--x4-canvas-soft); padding: 8px 0 72px;">
        <div class="max-w-3xl mx-auto px-5 space-y-5">

            <x-storefront.reveal>
            <div style="background-color: var(--x4-violet-soft); border-radius: var(--x4-r-lg); padding: 24px;">
                <p class="x4-body-lg" style="color: var(--x4-primary-deep);">
                    At <strong style="font-weight: 500;">XTRA4U</strong>, we value your privacy and are committed to protecting your personal information.
                </p>
            </div>
            </x-storefront.reveal>

            {{-- Information We Collect --}}
            <x-storefront.reveal :delay="60">
            <div class="x4-panel" style="padding: 28px;">
                <div class="flex items-center gap-3 mb-4">
                    <span class="x4-service-icon" style="width: 40px; height: 40px;">
                        <x-storefront.icon name="document" class="w-5 h-5" />
                    </span>
                    <h2 class="x4-heading-lg" style="color: var(--x4-ink);">Information We Collect</h2>
                </div>
                <p class="x4-body-md mb-4" style="color: var(--x4-ink-sec);">We may collect the following information:</p>
                <ul class="space-y-2.5">
                    @foreach ([
                        'Name, phone number, and email address',
                        'Account and transaction details',
                        'Login and usage data',
                    ] as $item)
                        <li class="flex items-start gap-2.5">
                            <x-storefront.icon name="check" class="w-4 h-4 flex-shrink-0 mt-0.5" style="color: var(--x4-violet);" />
                            <span class="x4-body-md" style="color: var(--x4-ink-sec);">{{ $item }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
            </x-storefront.reveal>

            {{-- How We Use Your Information --}}
            <x-storefront.reveal :delay="90">
            <div class="x4-panel" style="padding: 28px;">
                <div class="flex items-center gap-3 mb-4">
                    <span class="x4-service-icon" style="width: 40px; height: 40px; background-color: #16a34a; box-shadow: 0 4px 12px rgba(22,163,74,0.3);">
                        <x-storefront.icon name="shield" class="w-5 h-5" />
                    </span>
                    <h2 class="x4-heading-lg" style="color: var(--x4-ink);">How We Use Your Information</h2>
                </div>
                <p class="x4-body-md mb-4" style="color: var(--x4-ink-sec);">Your information is used to:</p>
                <ul class="space-y-2.5">
                    @foreach ([
                        'Provide and improve our services',
                        'Process transactions securely',
                        'Communicate important updates and support',
                        'Manage reseller and vendor accounts',
                    ] as $item)
                        <li class="flex items-start gap-2.5">
                            <x-storefront.icon name="check" class="w-4 h-4 flex-shrink-0 mt-0.5" style="color: #16a34a;" />
                            <span class="x4-body-md" style="color: var(--x4-ink-sec);">{{ $item }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
            </x-storefront.reveal>

            {{-- Data Protection --}}
            <x-storefront.reveal :delay="120">
            <div style="background-color: var(--x4-canvas); border: 1px solid var(--x4-hairline); border-radius: var(--x4-r-lg); padding: 28px;">
                <div class="flex items-center gap-3 mb-3">
                    <span class="x4-service-icon" style="width: 40px; height: 40px; background-color: #4f46e5; box-shadow: 0 4px 12px rgba(79,70,229,0.3);">
                        <x-storefront.icon name="lock" class="w-5 h-5" />
                    </span>
                    <h2 class="x4-heading-lg" style="color: var(--x4-ink);">Data Protection</h2>
                </div>
                <p class="x4-body-md" style="color: var(--x4-ink-sec);">
                    We take appropriate security measures to protect your data against unauthorized access, alteration, or disclosure.
                </p>
            </div>
            </x-storefront.reveal>

            {{-- Third Parties --}}
            <x-storefront.reveal :delay="150">
            <div class="x4-panel" style="padding: 28px;">
                <div class="flex items-center gap-3 mb-3">
                    <span class="x4-service-icon" style="width: 40px; height: 40px; background-color: #9333ea; box-shadow: 0 4px 12px rgba(147,51,234,0.3);">
                        <x-storefront.icon name="users" class="w-5 h-5" />
                    </span>
                    <h2 class="x4-heading-lg" style="color: var(--x4-ink);">Third Parties</h2>
                </div>
                <p class="x4-body-md" style="color: var(--x4-ink-sec);">
                    XTRA4U does not sell or share your personal information with third parties, except where required by law or necessary to deliver our services.
                </p>
            </div>
            </x-storefront.reveal>

            {{-- Agreement --}}
            <x-storefront.reveal :delay="180">
            <div class="text-center" style="background-color: var(--x4-canvas-soft); border: 1px solid var(--x4-hairline); border-radius: var(--x4-r-lg); padding: 24px;">
                <p class="x4-body-md" style="color: var(--x4-ink-sec);">
                    By using XTRA4U, you agree to the collection and use of your information as described in this policy.
                </p>
            </div>
            </x-storefront.reveal>
        </div>
    </section>

    {{-- ============================================================
         Back to Home
         ============================================================ --}}
    <section style="background-color: var(--x4-canvas); border-top: 1px solid var(--x4-hairline); padding: 40px 0;">
        <div class="max-w-3xl mx-auto px-5 text-center">
            <x-storefront.btn :href="route('storefront.index')" variant="primary">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Back to Home
            </x-storefront.btn>
        </div>
    </section>
</div>
@endsection
