{{--
    vendor/login.blade.php

    Vendor sign-in (route: GET /vendor/login, VendorAuthController@showLoginForm).
    Converted from a standalone HTML document to the shared layout so it
    picks up the same `x4` design system as the rest of the storefront
    journey. Every functional piece is preserved exactly:
      - form action/method/@csrf, field names (email, password, remember)
      - the password-visibility toggle script and its element ids
        (toggle_password_visibility, password, icon_eye, icon_eye_off)
      - the `session('vendor_whatsapp_url')` support CTA
        VendorAuthController flashes when an unapproved vendor tries to log in
      - every route() call
--}}
@extends('layouts.app')

@section('title', 'Vendor Login - XTRA4U')
@section('description', 'Secure login portal for XTRA4U vendors')

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
    <section class="relative overflow-hidden" style="background: #fff; min-height: calc(100vh - 64px);">
        <div class="x4-hero-wash absolute inset-0" aria-hidden="true" style="pointer-events: none;"></div>

        <div class="relative max-w-md mx-auto px-5" style="padding-top: 56px; padding-bottom: 72px;">

            <x-storefront.reveal from="up" class="text-center mb-8">
                <div class="mx-auto mb-5 flex items-center justify-center" style="width: 64px; height: 64px; border-radius: 9999px; background-color: var(--x4-violet);">
                    <svg class="w-7 h-7" fill="none" stroke="#fff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                    </svg>
                </div>
                <h1 class="x4-display-lg mb-2" style="color: var(--x4-ink-strong);">Vendor Portal</h1>
                <p class="x4-body-md" style="color: var(--x4-ink-body);">Sign in to manage your store and orders</p>
            </x-storefront.reveal>

            <x-storefront.reveal :delay="60">
            <div class="x4-panel" style="padding: 28px;">
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

                                @if (session('vendor_whatsapp_url'))
                                    <a href="{{ session('vendor_whatsapp_url') }}"
                                       target="_blank"
                                       rel="noopener noreferrer"
                                       class="mt-3 inline-flex items-center gap-2"
                                       style="padding: 8px 16px; background-color: #22c55e; color: #fff; font-size: 13px; font-weight: 500; border-radius: var(--x4-r-md);"
                                    >
                                        <x-storefront.icon name="whatsapp" class="w-4 h-4" />
                                        Chat on WhatsApp
                                    </a>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif

                <form method="POST" action="{{ route('vendor.login') }}">
                    @csrf

                    <div class="mb-4">
                        <label for="email" class="x4-caption block mb-1.5" style="color: var(--x4-ink-sec); font-weight: 500;">Email Address <span style="color: #dc2626;">*</span></label>
                        <div class="relative">
                            <x-storefront.icon name="mail" class="w-4 h-4 absolute" style="left: 14px; top: 50%; transform: translateY(-50%); color: var(--x4-ink-mute); pointer-events: none;" />
                            <input
                                type="email" name="email" id="email"
                                value="{{ old('email') }}"
                                required autofocus
                                placeholder="your@email.com"
                                class="x4-input"
                                style="padding-left: 38px;"
                            >
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="password" class="x4-caption block mb-1.5" style="color: var(--x4-ink-sec); font-weight: 500;">Password <span style="color: #dc2626;">*</span></label>
                        <div class="relative">
                            <x-storefront.icon name="lock" class="w-4 h-4 absolute" style="left: 14px; top: 50%; transform: translateY(-50%); color: var(--x4-ink-mute); pointer-events: none;" />
                            <input
                                type="password" name="password" id="password"
                                required
                                placeholder="Enter your password"
                                class="x4-input"
                                style="padding-left: 38px; padding-right: 38px;"
                            >
                            <button
                                type="button"
                                id="toggle_password_visibility"
                                class="absolute"
                                style="right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--x4-ink-mute); display: flex;"
                                aria-label="Show password"
                            >
                                <svg id="icon_eye" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    <path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                                <svg id="icon_eye_off" class="w-4 h-4 hidden" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.542-7a9.956 9.956 0 012.223-3.592m3.086-2.29A9.956 9.956 0 0112 5c4.478 0 8.268 2.943 9.542 7a9.963 9.963 0 01-4.124 5.303M15 12a3 3 0 00-3-3m0 0a2.99 2.99 0 00-2.12.879M12 9v0m0 6a3 3 0 01-3-3" />
                                    <path d="M3 3l18 18" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="flex items-center justify-between mb-6">
                        <label class="flex items-center gap-2 x4-caption" style="color: var(--x4-ink-sec); cursor: pointer;">
                            <input type="checkbox" name="remember" id="remember" style="accent-color: var(--x4-violet); width: 16px; height: 16px;">
                            Remember me
                        </label>
                        <a href="{{ route('vendor.password.forgot') }}" class="x4-caption x4-link x4-link-accent" style="color: var(--x4-violet); font-weight: 500;">
                            Forgot password?
                        </a>
                    </div>

                    <button type="submit" class="x4-btn x4-btn-primary w-full" style="padding: 13px 22px;">
                        Sign In to Vendor Portal
                    </button>
                </form>

                <p class="x4-caption text-center mt-6" style="color: var(--x4-ink-mute);">
                    Don't have an account?
                    <a href="{{ route('vendor.request.form') }}" class="x4-link x4-link-accent" style="color: var(--x4-violet); font-weight: 500;">Apply to become a vendor</a>
                </p>
            </div>
            </x-storefront.reveal>

            <div class="text-center mt-6">
                <a href="{{ route('storefront.index') }}" class="x4-caption x4-link x4-link-accent inline-flex items-center gap-2" style="color: var(--x4-ink-mute); font-weight: 500;">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                    </svg>
                    Back to homepage
                </a>
            </div>
        </div>
    </section>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const toggle = document.getElementById('toggle_password_visibility');
        const password = document.getElementById('password');
        const eye = document.getElementById('icon_eye');
        const eyeOff = document.getElementById('icon_eye_off');

        if (!toggle || !password || !eye || !eyeOff) return;

        const setVisible = (visible) => {
            password.type = visible ? 'text' : 'password';
            eye.classList.toggle('hidden', visible);
            eyeOff.classList.toggle('hidden', !visible);
            toggle.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
        };

        // default: hidden
        setVisible(false);

        toggle.addEventListener('click', function () {
            setVisible(password.type === 'password');
        });
    });
</script>
@endsection
