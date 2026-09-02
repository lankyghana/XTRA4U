{{--
    vendor/forgot-password.blade.php

    Vendor password-reset request (route: GET /vendor/forgot-password,
    VendorAuthController@showForgotPasswordForm). Converted from a
    standalone HTML document to the shared layout for the same `x4`
    design system as the rest of the storefront journey. Form
    action/method/@csrf/field name, the session('status') success box,
    and every route() call are preserved exactly.
--}}
@extends('layouts.app')

@section('title', 'Forgot Password - XTRA4U Vendor Portal')
@section('description', 'Reset your XTRA4U vendor account password')

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
                        <path d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                    </svg>
                </div>
                <h1 class="x4-display-lg mb-2" style="color: var(--x4-ink-strong);">Forgot Password?</h1>
                <p class="x4-body-md" style="color: var(--x4-ink-body);">No worries, we'll send you reset instructions.</p>
            </x-storefront.reveal>

            <x-storefront.reveal :delay="60">
            <div class="x4-panel" style="overflow: hidden;">
                <div style="padding: 28px;">
                    @if (session('status'))
                        <div class="mb-6 flex items-start gap-3" style="background-color: #f0fdf4; border-left: 3px solid #16a34a; border-radius: var(--x4-r-md); padding: 14px 16px;">
                            <x-storefront.icon name="check" class="w-4 h-4 flex-shrink-0 mt-0.5" style="color: #16a34a;" />
                            <p class="x4-caption" style="color: #166534;">{{ session('status') }}</p>
                        </div>
                    @endif

                    @if ($errors->any())
                        <div class="mb-6" style="background-color: #fef2f2; border-left: 3px solid #dc2626; border-radius: var(--x4-r-md); padding: 14px 16px;">
                            <ul class="space-y-1">
                                @foreach ($errors->all() as $error)
                                    <li class="x4-caption" style="color: #b91c1c; list-style: disc; margin-left: 16px;">{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('vendor.password.email') }}">
                        @csrf

                        <div class="mb-2">
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
                            <p class="x4-caption mt-2" style="color: var(--x4-ink-mute);">Enter the email address associated with your vendor account.</p>
                        </div>

                        <button type="submit" class="x4-btn x4-btn-primary w-full mt-4" style="padding: 13px 22px;">
                            Send Reset Link
                        </button>
                    </form>
                </div>

                <div class="text-center" style="padding: 16px 28px; background-color: var(--x4-canvas-soft); border-top: 1px solid var(--x4-hairline);">
                    <p class="x4-caption" style="color: var(--x4-ink-mute);">
                        Remember your password?
                        <a href="{{ route('vendor.login.form') }}" class="x4-link x4-link-accent" style="color: var(--x4-violet); font-weight: 500;">Sign in</a>
                    </p>
                </div>
            </div>
            </x-storefront.reveal>

            <div class="text-center mt-6">
                <a href="{{ route('vendor.login.form') }}" class="x4-caption x4-link x4-link-accent inline-flex items-center gap-2" style="color: var(--x4-ink-mute); font-weight: 500;">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                    </svg>
                    Back to Login
                </a>
            </div>
        </div>
    </section>
</div>
@endsection
