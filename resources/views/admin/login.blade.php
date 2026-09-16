{{--
    admin/login.blade.php

    Admin sign-in (route: GET /admin/login, AdminAuthController@showLoginForm).
    Visual redesign only — form action/method/@csrf, field names (email,
    password, remember), old('email'), the @error bags, session('status'),
    and route('storefront.index') are all preserved exactly. Only this
    page changed; the shared admin panel layout (admin-layout.blade.php)
    was not touched, since only /admin/login was asked for.
--}}
@extends('layouts.app')

@section('title', 'Admin Login - XTRA4U')
@section('description', 'Secure access for XTRA4U administrators')

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
                <div class="mx-auto mb-5 flex items-center justify-center" style="width: 64px; height: 64px; border-radius: 9999px; background-color: var(--x4-brand-dark);">
                    <x-storefront.icon name="shield" class="w-7 h-7" style="color: #fff;" />
                </div>
                <h1 class="x4-display-lg mb-2" style="color: var(--x4-ink-strong);">Admin Portal</h1>
                <p class="x4-body-md" style="color: var(--x4-ink-body);">Secure access for administrators</p>
            </x-storefront.reveal>

            <x-storefront.reveal :delay="60">
            <div class="x4-panel" style="overflow: hidden;">
                <div class="flex items-center gap-2.5" style="background-color: var(--x4-brand-dark); padding: 14px 24px;">
                    <x-storefront.icon name="shield" class="w-4 h-4" style="color: var(--x4-primary-sub);" />
                    <span class="x4-caption" style="color: #fff; font-weight: 500;">Secure administrator access only</span>
                </div>

                <div style="padding: 28px;">
                    @if (session('status'))
                        <div class="mb-6 flex items-start gap-3" style="background-color: #f0fdf4; border-left: 3px solid #16a34a; border-radius: var(--x4-r-md); padding: 14px 16px;">
                            <x-storefront.icon name="check" class="w-4 h-4 flex-shrink-0 mt-0.5" style="color: #16a34a;" />
                            <p class="x4-caption" style="color: #166534; font-weight: 500;">{{ session('status') }}</p>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('admin.login.submit') }}">
                        @csrf

                        <div class="mb-4">
                            <label for="email" class="x4-caption block mb-1.5" style="color: var(--x4-ink-sec); font-weight: 500;">Email Address <span style="color: #dc2626;">*</span></label>
                            <div class="relative">
                                <x-storefront.icon name="mail" class="w-4 h-4 absolute" style="left: 14px; top: 50%; transform: translateY(-50%); color: var(--x4-ink-mute); pointer-events: none;" />
                                <input
                                    type="email" name="email" id="email"
                                    value="{{ old('email') }}"
                                    required autofocus
                                    placeholder="you@example.com"
                                    class="x4-input"
                                    style="padding-left: 38px;"
                                >
                            </div>
                            @error('email')
                                <p class="x4-caption mt-1.5" style="color: #dc2626;">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="mb-5">
                            <label for="password" class="x4-caption block mb-1.5" style="color: var(--x4-ink-sec); font-weight: 500;">Password <span style="color: #dc2626;">*</span></label>
                            <div class="relative">
                                <x-storefront.icon name="lock" class="w-4 h-4 absolute" style="left: 14px; top: 50%; transform: translateY(-50%); color: var(--x4-ink-mute); pointer-events: none;" />
                                <input
                                    type="password" name="password" id="password"
                                    required
                                    placeholder="Enter your password"
                                    class="x4-input"
                                    style="padding-left: 38px;"
                                >
                            </div>
                            @error('password')
                                <p class="x4-caption mt-1.5" style="color: #dc2626;">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex items-center justify-between mb-6">
                            <label class="flex items-center gap-2 x4-caption" style="color: var(--x4-ink-sec); cursor: pointer;">
                                <input type="checkbox" name="remember" id="remember" style="accent-color: var(--x4-violet); width: 16px; height: 16px;">
                                Remember me
                            </label>
                            <span class="x4-caption" style="color: var(--x4-ink-mute);">Need access? Contact IT</span>
                        </div>

                        <button type="submit" class="x4-btn x4-btn-primary w-full" style="padding: 13px 22px; background-color: var(--x4-brand-dark); border-color: var(--x4-brand-dark);">
                            Sign In to Admin Portal
                        </button>
                    </form>
                </div>
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
@endsection
