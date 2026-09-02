{{--
    pages/terms.blade.php

    Static legal page (route: GET /terms, closure-bound in web.php).
    Visual redesign only — every sentence of the terms text is preserved
    verbatim; only markup/classes changed.
--}}
@extends('layouts.app')

@section('title', 'Terms of Service - XTRA4U')
@section('description', 'Terms of Service for XTRA4U - Read our terms and conditions for using our platform.')

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
                    <x-storefront.icon name="document" class="w-6 h-6" style="color: #fff;" />
                </div>

                <h1 class="x4-display-xl mb-3" style="color: var(--x4-ink-strong);">Terms of Service</h1>
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
                    By accessing or using <strong style="font-weight: 500;">XTRA4U</strong>, you agree to the following terms:
                </p>
            </div>
            </x-storefront.reveal>

            {{-- Use of Services --}}
            <x-storefront.reveal :delay="60">
            <div class="x4-panel" style="padding: 28px;">
                <div class="flex items-center gap-3 mb-4">
                    <span class="x4-service-icon" style="width: 40px; height: 40px;">
                        <x-storefront.icon name="shield" class="w-5 h-5" />
                    </span>
                    <h2 class="x4-heading-lg" style="color: var(--x4-ink);">Use of Services</h2>
                </div>
                <ul class="space-y-2.5">
                    <li class="flex items-start gap-2.5">
                        <x-storefront.icon name="check" class="w-4 h-4 flex-shrink-0 mt-0.5" style="color: var(--x4-violet);" />
                        <span class="x4-body-md" style="color: var(--x4-ink-sec);">You must provide accurate information when registering.</span>
                    </li>
                    <li class="flex items-start gap-2.5">
                        <x-storefront.icon name="check" class="w-4 h-4 flex-shrink-0 mt-0.5" style="color: var(--x4-violet);" />
                        <span class="x4-body-md" style="color: var(--x4-ink-sec);">All services are for lawful use only.</span>
                    </li>
                    <li class="flex items-start gap-2.5">
                        <x-storefront.icon name="close" class="w-4 h-4 flex-shrink-0 mt-0.5" style="color: #dc2626;" />
                        <span class="x4-body-md" style="color: var(--x4-ink-sec);">Any attempt to abuse, hack, or manipulate the platform will result in account suspension.</span>
                    </li>
                </ul>
            </div>
            </x-storefront.reveal>

            {{-- Payments & Transactions --}}
            <x-storefront.reveal :delay="90">
            <div class="x4-panel" style="padding: 28px;">
                <div class="flex items-center gap-3 mb-4">
                    <span class="x4-service-icon" style="width: 40px; height: 40px; background-color: #16a34a; box-shadow: 0 4px 12px rgba(22,163,74,0.3);">
                        <x-storefront.icon name="zap" class="w-5 h-5" />
                    </span>
                    <h2 class="x4-heading-lg" style="color: var(--x4-ink);">Payments &amp; Transactions</h2>
                </div>
                <ul class="space-y-2.5">
                    <li class="flex items-start gap-2.5">
                        <x-storefront.icon name="check" class="w-4 h-4 flex-shrink-0 mt-0.5" style="color: #16a34a;" />
                        <span class="x4-body-md" style="color: var(--x4-ink-sec);">All payments made on XTRA4U are final once services are delivered.</span>
                    </li>
                    <li class="flex items-start gap-2.5">
                        <x-storefront.icon name="check" class="w-4 h-4 flex-shrink-0 mt-0.5" style="color: #16a34a;" />
                        <span class="x4-body-md" style="color: var(--x4-ink-sec);">Users are responsible for confirming details before completing transactions.</span>
                    </li>
                </ul>
            </div>
            </x-storefront.reveal>

            {{-- Reseller & Vendor Accounts --}}
            <x-storefront.reveal :delay="120">
            <div class="x4-panel" style="padding: 28px;">
                <div class="flex items-center gap-3 mb-4">
                    <span class="x4-service-icon" style="width: 40px; height: 40px; background-color: #9333ea; box-shadow: 0 4px 12px rgba(147,51,234,0.3);">
                        <x-storefront.icon name="users" class="w-5 h-5" />
                    </span>
                    <h2 class="x4-heading-lg" style="color: var(--x4-ink);">Reseller &amp; Vendor Accounts</h2>
                </div>
                <ul class="space-y-2.5">
                    <li class="flex items-start gap-2.5">
                        <x-storefront.icon name="check" class="w-4 h-4 flex-shrink-0 mt-0.5" style="color: #9333ea;" />
                        <span class="x4-body-md" style="color: var(--x4-ink-sec);">Resellers and vendors must follow XTRA4U pricing and policies.</span>
                    </li>
                    <li class="flex items-start gap-2.5">
                        <x-storefront.icon name="close" class="w-4 h-4 flex-shrink-0 mt-0.5" style="color: #dc2626;" />
                        <span class="x4-body-md" style="color: var(--x4-ink-sec);">Any fraudulent activity will lead to immediate termination of the account without refund.</span>
                    </li>
                </ul>
            </div>
            </x-storefront.reveal>

            {{-- Service Availability --}}
            <x-storefront.reveal :delay="150">
            <div style="background-color: #fffbeb; border: 1px solid #fde68a; border-radius: var(--x4-r-lg); padding: 28px;">
                <div class="flex items-center gap-3 mb-3">
                    <span class="x4-service-icon" style="width: 40px; height: 40px; background-color: #d97706; box-shadow: 0 4px 12px rgba(217,119,6,0.3);">
                        <x-storefront.icon name="clock" class="w-5 h-5" />
                    </span>
                    <h2 class="x4-heading-lg" style="color: var(--x4-ink);">Service Availability</h2>
                </div>
                <p class="x4-body-md" style="color: var(--x4-ink-sec);">
                    We aim to provide uninterrupted service, but XTRA4U is not liable for delays or outages caused by network issues, system maintenance, or third-party providers.
                </p>
            </div>
            </x-storefront.reveal>

            {{-- Account Termination --}}
            <x-storefront.reveal :delay="180">
            <div style="background-color: #fef2f2; border: 1px solid #fecaca; border-radius: var(--x4-r-lg); padding: 28px;">
                <div class="flex items-center gap-3 mb-3">
                    <span class="x4-service-icon" style="width: 40px; height: 40px; background-color: #dc2626; box-shadow: 0 4px 12px rgba(220,38,38,0.3);">
                        <x-storefront.icon name="close" class="w-5 h-5" />
                    </span>
                    <h2 class="x4-heading-lg" style="color: var(--x4-ink);">Account Termination</h2>
                </div>
                <p class="x4-body-md" style="color: var(--x4-ink-sec);">
                    XTRA4U reserves the right to suspend or terminate any account that violates these terms.
                </p>
            </div>
            </x-storefront.reveal>

            {{-- Agreement --}}
            <x-storefront.reveal :delay="210">
            <div class="text-center" style="background-color: var(--x4-canvas); border: 1px solid var(--x4-hairline); border-radius: var(--x4-r-lg); padding: 24px;">
                <p class="x4-body-md" style="color: var(--x4-ink-sec);">
                    By continuing to use XTRA4U, you acknowledge that you have read, understood, and agree to be bound by these Terms of Service.
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
