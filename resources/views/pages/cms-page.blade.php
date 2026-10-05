{{--
    pages/cms-page.blade.php

    Renders a CMS-managed information page (Privacy, Terms, and any page an admin
    adds). `$page` is the payload built by CmsContent::payload(): the body is
    Markdown that has already been sanitized, so it is printed unescaped on purpose.
    `$preview` is true only on the admin-protected preview route.
--}}
@extends('layouts.app')

@section('title', $page['seo']['title'])
@section('description', $page['seo']['description'])

@push('head-seo')
    <x-cms.seo :seo="$page['seo']" :url="$canonicalUrl ?? null" />
    @if ($preview ?? false)
        <meta name="robots" content="noindex, nofollow">
    @endif
@endpush

{{-- Scope the storefront design system to this page only. --}}
@section('body-class', 'x4')

@php
    $mainStoreVendor = \App\Support\MainStore::vendor();
    $shopUrl = $mainStoreVendor
        ? route('storefront.vendor', ['vendor' => $mainStoreVendor->vendor_code])
        : route('checkout.show');

    $cards = $page['cards'];
    $hasSections = count($cards['sections']) > 0;
    $updated = ($preview ?? false)
        ? now()
        : \Illuminate\Support\Carbon::createFromTimestamp($page['published_at'] ?? $page['updated_at'] ?? time());
@endphp

@section('site-header')
    <x-storefront.header :shop-url="$shopUrl" />
@endsection

@section('site-footer')
    <x-storefront.footer :shop-url="$shopUrl" />
@endsection

@section('content')
<div class="x4-page" style="padding-top: 64px;">

    @if ($preview ?? false)
        <div role="status" style="background-color: #1c1e54; color: #fff; text-align: center; padding: 8px 16px; font-size: 13px;">
            Preview &mdash; this version is not published and is visible only to signed-in administrators.
        </div>
    @endif

    <x-cms.announcement-bar audience="public" />

    {{-- Hero --}}
    <section class="relative overflow-hidden" style="background: #fff;">
        <div class="x4-hero-wash absolute inset-0" aria-hidden="true" style="pointer-events: none;"></div>

        <div class="relative max-w-3xl mx-auto px-5 text-center" style="padding-top: 56px; padding-bottom: 48px;">
            <x-storefront.reveal from="up">
                <div class="mx-auto mb-5 flex items-center justify-center" style="width: 60px; height: 60px; border-radius: var(--x4-r-lg); background-color: var(--x4-violet);">
                    <x-storefront.icon :name="$page['icon']" class="w-6 h-6" style="color: #fff;" />
                </div>

                <h1 class="x4-display-xl mb-3" style="color: var(--x4-ink-strong);">{{ $page['title'] }}</h1>
                <p class="x4-caption" style="color: var(--x4-ink-mute);">Last updated: {{ $updated->format('F j, Y') }}</p>
            </x-storefront.reveal>
        </div>
    </section>

    {{-- Content --}}
    <section style="background-color: var(--x4-canvas-soft); padding: 8px 0 72px;">
        <div class="max-w-3xl mx-auto px-5 space-y-5">
            @if ($hasSections)
                @if (trim($cards['intro']) !== '')
                    <x-storefront.reveal>
                        <div style="background-color: var(--x4-violet-soft); border-radius: var(--x4-r-lg); padding: 24px;">
                            {!! $cards['intro'] !!}
                        </div>
                    </x-storefront.reveal>
                @endif

                @foreach ($cards['sections'] as $i => $section)
                    <x-storefront.reveal :delay="60 + $i * 30">
                        @if ($section['tinted'])
                            <div style="background-color: #fef2f2; border: 1px solid #fecaca; border-radius: var(--x4-r-lg); padding: 28px;">
                        @else
                            <div class="x4-panel" style="padding: 28px;">
                        @endif
                            <div class="flex items-center gap-3 {{ str_contains($section['html'], '<ul') ? 'mb-4' : 'mb-3' }}">
                                <span class="x4-service-icon" style="width: 40px; height: 40px;{{ $section['shadow'] ? ' background-color: '.$section['color'].'; box-shadow: 0 4px 12px '.$section['shadow'].';' : '' }}">
                                    <x-storefront.icon :name="$section['icon']" class="w-5 h-5" />
                                </span>
                                <h2 class="x4-heading-lg" style="color: var(--x4-ink);">{{ $section['title'] }}</h2>
                            </div>
                            {!! $section['html'] !!}
                        </div>
                    </x-storefront.reveal>
                @endforeach

                @if (trim($cards['closing']) !== '')
                    <x-storefront.reveal :delay="60 + count($cards['sections']) * 30">
                        <div class="text-center" style="background-color: var(--x4-canvas-soft); border: 1px solid var(--x4-hairline); border-radius: var(--x4-r-lg); padding: 24px;">
                            {!! $cards['closing'] !!}
                        </div>
                    </x-storefront.reveal>
                @endif
            @else
                <x-storefront.reveal>
                    <div class="x4-panel" style="padding: 28px;">
                        {!! \App\Support\Cms\CmsMarkdown::decorate($page['html'], 'card', 'var(--x4-violet)') !!}
                    </div>
                </x-storefront.reveal>
            @endif
        </div>
    </section>

    {{-- Back to Home --}}
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
