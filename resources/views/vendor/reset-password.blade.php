{{--
    vendor/reset-password.blade.php

    Vendor password reset (route: GET /vendor/reset-password/{token},
    VendorAuthController@showResetPasswordForm). Converted from a
    standalone HTML document to the shared layout for the same `x4`
    design system as login/forgot-password. Form action/method/@csrf,
    hidden token/email inputs, and the show/hide Alpine toggles are
    preserved exactly.
--}}
@extends('layouts.app')

@section('title', 'Reset Password - XTRA4U Vendor Portal')
@section('description', 'Create a new password for your XTRA4U vendor account')

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
                    <x-storefront.icon name="lock" class="w-7 h-7" style="color: #fff;" />
                </div>
                <h1 class="x4-display-lg mb-2" style="color: var(--x4-ink-strong);">Reset Password</h1>
                <p class="x4-body-md" style="color: var(--x4-ink-body);">Create a new password for your account</p>
            </x-storefront.reveal>

            <x-storefront.reveal :delay="60">
            <div class="x4-panel" style="padding: 28px;">
                @if ($errors->any())
                    <div class="mb-6" style="background-color: #fef2f2; border-left: 3px solid #dc2626; border-radius: var(--x4-r-md); padding: 16px;">
                        <ul class="space-y-1">
                            @foreach ($errors->all() as $error)
                                <li class="x4-caption" style="color: #b91c1c; list-style: disc; margin-left: 16px;">{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('vendor.password.update') }}" x-data="{ showPassword: false, showConfirmPassword: false }">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">
                    <input type="hidden" name="email" value="{{ $email }}">

                    <div class="mb-4">
                        <label class="x4-caption block mb-1.5" style="color: var(--x4-ink-sec); font-weight: 500;">Email Address</label>
                        <div class="relative">
                            <x-storefront.icon name="mail" class="w-4 h-4 absolute" style="left: 14px; top: 50%; transform: translateY(-50%); color: var(--x4-ink-mute); pointer-events: none;" />
                            <input
                                type="email" value="{{ $email }}" disabled
                                class="x4-input"
                                style="padding-left: 38px; background-color: var(--x4-canvas-soft); color: var(--x4-ink-mute);"
                            >
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="password" class="x4-caption block mb-1.5" style="color: var(--x4-ink-sec); font-weight: 500;">New Password <span style="color: #dc2626;">*</span></label>
                        <div class="relative">
                            <x-storefront.icon name="lock" class="w-4 h-4 absolute" style="left: 14px; top: 50%; transform: translateY(-50%); color: var(--x4-ink-mute); pointer-events: none;" />
                            <input
                                :type="showPassword ? 'text' : 'password'"
                                name="password" id="password"
                                required autofocus
                                placeholder="Enter new password"
                                class="x4-input"
                                style="padding-left: 38px; padding-right: 38px;"
                            >
                            <button
                                type="button"
                                @click="showPassword = !showPassword"
                                class="absolute"
                                style="right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--x4-ink-mute); display: flex;"
                                :aria-label="showPassword ? 'Hide password' : 'Show password'"
                            >
                                <x-storefront.icon name="eye" x-show="!showPassword" class="w-4 h-4" />
                                <x-storefront.icon name="eye-off" x-show="showPassword" x-cloak class="w-4 h-4" />
                            </button>
                        </div>
                        <p class="x4-caption mt-1.5" style="color: var(--x4-ink-mute);">Must be at least 8 characters long.</p>
                    </div>

                    <div class="mb-6">
                        <label for="password_confirmation" class="x4-caption block mb-1.5" style="color: var(--x4-ink-sec); font-weight: 500;">Confirm Password <span style="color: #dc2626;">*</span></label>
                        <div class="relative">
                            <x-storefront.icon name="check" class="w-4 h-4 absolute" style="left: 14px; top: 50%; transform: translateY(-50%); color: var(--x4-ink-mute); pointer-events: none;" />
                            <input
                                :type="showConfirmPassword ? 'text' : 'password'"
                                name="password_confirmation" id="password_confirmation"
                                required
                                placeholder="Confirm new password"
                                class="x4-input"
                                style="padding-left: 38px; padding-right: 38px;"
                            >
                            <button
                                type="button"
                                @click="showConfirmPassword = !showConfirmPassword"
                                class="absolute"
                                style="right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--x4-ink-mute); display: flex;"
                                :aria-label="showConfirmPassword ? 'Hide password' : 'Show password'"
                            >
                                <x-storefront.icon name="eye" x-show="!showConfirmPassword" class="w-4 h-4" />
                                <x-storefront.icon name="eye-off" x-show="showConfirmPassword" x-cloak class="w-4 h-4" />
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="x4-btn x4-btn-primary w-full" style="padding: 13px 22px;">
                        Reset Password
                    </button>
                </form>
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
