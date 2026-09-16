{{--
    vendor_request_pending.blade.php

    Reached after a vendor request submission (route: GET /vendor/request/pending,
    VendorController@requestPending). Visual redesign only — $vendorName,
    $whatsappUrl, $contactNumber, and both route() calls are unchanged.
--}}
@extends('layouts.app')

@section('title', 'Request Submitted - XTRA4U')
@section('description', 'Your vendor registration request has been submitted and is awaiting admin approval.')

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

        <div class="relative max-w-2xl mx-auto px-5" style="padding-top: 56px; padding-bottom: 72px;">

            {{-- ============================================================
                 Header
                 ============================================================ --}}
            <x-storefront.reveal from="up" class="text-center mb-9">
                <div
                    class="mx-auto mb-6 flex items-center justify-center"
                    style="width: 76px; height: 76px; border-radius: 9999px; background-color: #dcfce7;"
                >
                    <x-storefront.icon name="check" class="w-9 h-9" style="color: #16a34a;" />
                </div>

                <h1 class="x4-display-xl mb-3" style="color: var(--x4-ink-strong);">Request Submitted!</h1>
                <p class="x4-body-lg" style="color: var(--x4-ink-body);">
                    @if ($vendorName)
                        Thanks, {{ $vendorName }} &mdash; your vendor registration request has been received.
                    @else
                        Your vendor registration request has been received.
                    @endif
                </p>
            </x-storefront.reveal>

            {{-- ============================================================
                 Status card
                 ============================================================ --}}
            <x-storefront.reveal :delay="60">
            <div class="x4-panel text-center" style="padding: 32px 28px;">
                <span
                    class="inline-flex items-center gap-1.5 mb-5"
                    style="background-color: #fef3c7; color: #92400e; border-radius: var(--x4-r-pill); padding: 5px 14px;"
                >
                    <x-storefront.icon name="clock" class="w-3.5 h-3.5" />
                    <span class="x4-caption" style="font-weight: 500;">Pending Approval</span>
                </span>

                <p class="x4-body-md mb-6" style="color: var(--x4-ink-sec);">
                    Your account is now pending approval. To speed up the review process, please contact admin using the number below.
                </p>

                @if ($whatsappUrl)
                    <div style="background-color: var(--x4-canvas-soft); border: 1px solid var(--x4-hairline); border-radius: var(--x4-r-lg); padding: 24px; margin-bottom: 24px;">
                        <p class="x4-micro-cap mb-3" style="color: var(--x4-ink-mute);">Contact Admin For Approval</p>
                        <p class="x4-tnum mb-4" style="font-size: 22px; font-weight: 500; color: var(--x4-ink);">{{ $contactNumber }}</p>
                        <a
                            href="{{ $whatsappUrl }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="x4-btn"
                            style="background-color: #22c55e; color: #fff; border: 1px solid #22c55e; padding: 13px 26px;"
                        >
                            <x-storefront.icon name="whatsapp" class="w-4 h-4" />
                            Chat on WhatsApp
                        </a>
                    </div>
                @else
                    <div style="background-color: #fffbeb; border: 1px solid #fde68a; border-radius: var(--x4-r-lg); padding: 20px; margin-bottom: 24px;">
                        <p class="x4-caption" style="color: #92400e;">Please contact admin for approval.</p>
                    </div>
                @endif

                <p class="x4-caption mb-8" style="color: var(--x4-ink-mute);">
                    You'll be able to log in as soon as your account is approved.
                </p>

                <div class="flex flex-col sm:flex-row justify-center gap-3">
                    <x-storefront.btn :href="route('vendor.login.form')" variant="primary" class="justify-center">
                        Go to Vendor Login
                    </x-storefront.btn>
                    <x-storefront.btn :href="route('storefront.index')" variant="outline" class="justify-center">
                        Back to Home
                    </x-storefront.btn>
                </div>
            </div>
            </x-storefront.reveal>
        </div>
    </section>
</div>
@endsection
