{{--
    pages/services-closed.blade.php

    Shared "unavailable" fallback (503) for every official XTRA4U service
    page (App\Http\Controllers\PlatformServiceController::unavailable(),
    and AfaRegistrationController@show's closed-category guard). Visual
    redesign only — $title, $message, $backHref, $backLabel and their
    exact defaults are unchanged.
--}}
@extends('layouts.app')

@section('title', 'Service Unavailable - XTRA4U')
@section('description', 'This service is temporarily unavailable on XTRA4U')

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

        <div class="relative max-w-lg mx-auto px-5 text-center" style="padding-top: 96px; padding-bottom: 96px;">
            <x-storefront.reveal from="up">
                <div class="mx-auto mb-6 flex items-center justify-center" style="width: 76px; height: 76px; border-radius: 9999px; background-color: #fef3c7;">
                    <svg class="w-8 h-8" fill="none" stroke="#b45309" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                </div>

                <h1 class="x4-display-lg mb-3" style="color: var(--x4-ink-strong);">
                    {{ $title ?? 'Service Temporarily Unavailable' }}
                </h1>

                <p class="x4-body-lg mb-8" style="color: var(--x4-ink-body);">
                    {{ $message ?? \App\Support\ServiceAvailability::message() }}
                </p>

                <div class="flex justify-center mb-6">
                    <x-storefront.btn :href="$backHref ?? route('storefront.index')" variant="primary">
                        {{ $backLabel ?? 'Back to Store' }}
                    </x-storefront.btn>
                </div>

                <p class="x4-caption" style="color: var(--x4-ink-mute);">
                    Other services may still be available. Please check back soon.
                </p>
            </x-storefront.reveal>
        </div>
    </section>
</div>
@endsection
