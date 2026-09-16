{{--
    vendor_request.blade.php

    Vendor sign-up request (route: GET /vendor/request, VendorController@showRequestForm).
    Visual redesign only — form action/method/@csrf and every field name
    (name, email, phone_number, password, affiliate_vendor_code) are
    preserved exactly, including the `terms` checkbox's pre-existing lack
    of a `name` attribute (so it was never submitted server-side; not
    something this redesign changes). The only functional tweak is the
    "Login here" link, which pointed at the `vendor.login` route name —
    that's the POST /vendor/login route, not the login form — swapped for
    `vendor.login.form`. Both resolve to the same URI, so this changes
    nothing about where the link actually goes; it now just names the
    route it means.
--}}
@extends('layouts.app')

@section('title', 'Become a Vendor - XTRA4U')
@section('description', 'Join our platform as a verified vendor and reach thousands of customers across Ghana.')

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
    <section class="relative overflow-hidden" style="background: #fff;">
        <div class="x4-hero-wash absolute inset-0" aria-hidden="true" style="pointer-events: none;"></div>

        <div class="relative max-w-2xl mx-auto px-5" style="padding-top: 48px; padding-bottom: 72px;">

            <x-storefront.reveal from="up" class="text-center mb-8">
                <div class="mx-auto mb-5 flex items-center justify-center" style="width: 64px; height: 64px; border-radius: 9999px; background-color: var(--x4-violet);">
                    <svg class="w-7 h-7" fill="none" stroke="#fff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                </div>
                <h1 class="x4-display-lg mb-2" style="color: var(--x4-ink-strong);">Become a Vendor</h1>
                <p class="x4-body-lg" style="color: var(--x4-ink-body);">
                    Join thousands of vendors on XTRA4U and grow your business with our verified platform.
                </p>
            </x-storefront.reveal>

            <x-storefront.reveal :delay="60">
            <div class="x4-panel" style="overflow: hidden;">

                {{-- Benefits banner --}}
                <div style="background-color: var(--x4-brand-dark); padding: 28px 28px 32px;">
                    <h3 class="x4-heading-md mb-4" style="color: #fff;">Why Join XTRA4U?</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        @foreach ([
                            'Secure Payments',
                            'Wide Reach',
                            'Easy Management',
                        ] as $benefit)
                            <div class="flex items-start gap-2">
                                <x-storefront.icon name="check" class="w-4 h-4 flex-shrink-0 mt-0.5" style="color: var(--x4-primary-sub);" />
                                <span class="x4-caption" style="color: rgba(255,255,255,0.85);">{{ $benefit }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Form --}}
                <div style="padding: 28px;">
                    @if ($errors->any())
                        <div class="mb-6" style="background-color: #fef2f2; border-left: 3px solid #dc2626; border-radius: var(--x4-r-md); padding: 16px;">
                            <div class="flex items-start gap-3">
                                <x-storefront.icon name="close" class="w-4 h-4 flex-shrink-0 mt-0.5" style="color: #dc2626;" />
                                <div>
                                    <p class="x4-caption mb-2" style="color: #991b1b; font-weight: 500;">Please correct the following errors:</p>
                                    <ul class="space-y-1">
                                        @foreach ($errors->all() as $error)
                                            <li class="x4-caption" style="color: #b91c1c; list-style: disc; margin-left: 16px;">{{ $error }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('vendor.request.submit') }}">
                        @csrf

                        <div class="mb-4">
                            <label for="name" class="x4-caption block mb-1.5" style="color: var(--x4-ink-sec); font-weight: 500;">Business/Full Name <span style="color: #dc2626;">*</span></label>
                            <div class="relative">
                                <x-storefront.icon name="users" class="w-4 h-4 absolute" style="left: 14px; top: 50%; transform: translateY(-50%); color: var(--x4-ink-mute); pointer-events: none;" />
                                <input
                                    type="text" name="name" id="name"
                                    value="{{ old('name') }}"
                                    required
                                    placeholder="Enter your business or full name"
                                    class="x4-input"
                                    style="padding-left: 38px;"
                                >
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="email" class="x4-caption block mb-1.5" style="color: var(--x4-ink-sec); font-weight: 500;">Email Address <span style="color: #dc2626;">*</span></label>
                            <div class="relative">
                                <x-storefront.icon name="mail" class="w-4 h-4 absolute" style="left: 14px; top: 50%; transform: translateY(-50%); color: var(--x4-ink-mute); pointer-events: none;" />
                                <input
                                    type="email" name="email" id="email"
                                    value="{{ old('email') }}"
                                    required
                                    placeholder="your@email.com"
                                    class="x4-input"
                                    style="padding-left: 38px;"
                                >
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="phone_number" class="x4-caption block mb-1.5" style="color: var(--x4-ink-sec); font-weight: 500;">Phone Number <span style="color: #dc2626;">*</span></label>
                            <div class="relative">
                                <x-storefront.icon name="phone" class="w-4 h-4 absolute" style="left: 14px; top: 50%; transform: translateY(-50%); color: var(--x4-ink-mute); pointer-events: none;" />
                                <input
                                    type="text" name="phone_number" id="phone_number"
                                    value="{{ old('phone_number') }}"
                                    required
                                    placeholder="+233 XX XXX XXXX"
                                    class="x4-input"
                                    style="padding-left: 38px;"
                                >
                            </div>
                            <p class="x4-caption mt-1.5" style="color: var(--x4-ink-mute);">Include country code (e.g., +233)</p>
                        </div>

                        <div class="mb-4">
                            <label for="password" class="x4-caption block mb-1.5" style="color: var(--x4-ink-sec); font-weight: 500;">Password <span style="color: #dc2626;">*</span></label>
                            <div class="relative">
                                <x-storefront.icon name="lock" class="w-4 h-4 absolute" style="left: 14px; top: 50%; transform: translateY(-50%); color: var(--x4-ink-mute); pointer-events: none;" />
                                <input
                                    type="password" name="password" id="password"
                                    required
                                    placeholder="Create a strong password"
                                    class="x4-input"
                                    style="padding-left: 38px;"
                                >
                            </div>
                            <p class="x4-caption mt-1.5" style="color: var(--x4-ink-mute);">Minimum 8 characters recommended</p>
                        </div>

                        <div class="mb-5">
                            <label for="affiliate_vendor_code" class="x4-caption block mb-1.5" style="color: var(--x4-ink-sec); font-weight: 500;">
                                Affiliate Vendor Code <span style="color: var(--x4-ink-mute); font-weight: 400;">(optional)</span>
                            </label>
                            <div class="relative">
                                <x-storefront.icon name="users" class="w-4 h-4 absolute" style="left: 14px; top: 50%; transform: translateY(-50%); color: var(--x4-ink-mute); pointer-events: none;" />
                                <input
                                    type="text" name="affiliate_vendor_code" id="affiliate_vendor_code"
                                    value="{{ old('affiliate_vendor_code') }}"
                                    maxlength="10"
                                    placeholder="e.g., DANI7X9K2L"
                                    class="x4-input"
                                    style="padding-left: 38px; text-transform: uppercase;"
                                >
                            </div>
                            <p class="x4-caption mt-1.5" style="color: var(--x4-ink-mute);">Enter the vendor code of who referred you</p>
                        </div>

                        {{-- Terms checkbox intentionally has no `name` attribute — matches the
                             pre-existing markup; VendorController@submitRequest never validates
                             a terms field, so this is unchanged behaviour, not a regression. --}}
                        <div class="mb-6" style="background-color: var(--x4-canvas-soft); border: 1px solid var(--x4-hairline); border-radius: var(--x4-r-md); padding: 16px;">
                            <label for="terms" class="flex items-start gap-3" style="cursor: pointer;">
                                <input type="checkbox" id="terms" required style="accent-color: var(--x4-violet); width: 16px; height: 16px; margin-top: 2px; flex-shrink: 0;">
                                <span>
                                    <span class="x4-caption" style="color: var(--x4-ink); font-weight: 500; display: block;">I agree to the Terms and Conditions</span>
                                    <span class="x4-caption" style="color: var(--x4-ink-mute);">By submitting this form, you agree to our vendor terms and platform policies.</span>
                                </span>
                            </label>
                        </div>

                        <button type="submit" class="x4-btn x4-btn-primary w-full" style="padding: 13px 22px;">
                            Submit Vendor Request
                        </button>
                    </form>

                    <p class="x4-caption text-center mt-6" style="color: var(--x4-ink-mute);">
                        Already have an account?
                        <a href="{{ route('vendor.login.form') }}" class="x4-link x4-link-accent" style="color: var(--x4-violet); font-weight: 500;">Login here</a>
                    </p>
                </div>
            </div>
            </x-storefront.reveal>

            <p class="x4-caption text-center mt-6" style="color: var(--x4-ink-mute);">
                After submitting, please contact admin to have your request reviewed and approved.
            </p>
        </div>
    </section>
</div>
@endsection
