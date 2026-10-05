{{--
    pages/about.blade.php

    Static marketing page (route: GET /about, closure-bound in web.php).
    Visual redesign only — all copy is preserved verbatim; every link
    (route('storefront.index'), route('vendor.request.form')) is unchanged.
--}}
@extends('layouts.app')

@php $seo = $cms->structuredSeo('about'); @endphp
@section('title', $seo['title'])
@section('description', $seo['description'])
@push('head-seo')
    <x-cms.seo :seo="$seo" :url="route('about')" />
@endpush

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
@php
    $hero = $cms->section('about', 'hero');
    $mission = $cms->section('about', 'mission');
    $values = $cms->section('about', 'values');
    $cta = $cms->section('about', 'cta');
@endphp
<div class="x4-page" style="padding-top: 64px;">

    <x-cms.preview-bar />
    <x-cms.announcement-bar audience="public" />

    {{-- ============================================================
         Hero
         ============================================================ --}}
    <section class="relative overflow-hidden" style="background: #fff;">
        <div class="x4-hero-wash absolute inset-0" aria-hidden="true" style="pointer-events: none;"></div>

        <div class="relative max-w-3xl mx-auto px-5 text-center" style="padding-top: 64px; padding-bottom: 56px;">
            <x-storefront.reveal from="up">
                <x-storefront.eyebrow>{{ $hero['eyebrow'] }}</x-storefront.eyebrow>

                <h1 class="x4-display-xxl mt-4 mb-4" style="color: var(--x4-ink-strong);">
                    {{ $cms->accent($hero['title']) }}
                </h1>

                <p class="x4-body-lg" style="color: var(--x4-ink-body);">
                    {{ $hero['description'] }}
                </p>
            </x-storefront.reveal>
        </div>
    </section>

    {{-- ============================================================
         What we do / mission
         ============================================================ --}}
    @if ($cms->sectionVisible('about', 'mission'))
    <section style="background-color: var(--x4-canvas-soft); padding: 8px 0 80px;">
        <div class="max-w-5xl mx-auto px-5">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                @foreach ($mission['cards'] as $i => $card)
                    <x-storefront.reveal :delay="$i * 90" class="flex">
                        <div class="x4-panel" style="padding: 28px; display: flex; flex-direction: column;">
                            <div class="x4-service-icon mb-5">
                                <x-storefront.icon :name="$card['icon']" class="w-5 h-5" />
                            </div>
                            <h3 class="x4-heading-md mb-2" style="color: var(--x4-ink);">{{ $card['title'] }}</h3>
                            <p class="x4-body-md" style="color: var(--x4-ink-sec);">{{ $card['body'] }}</p>
                        </div>
                    </x-storefront.reveal>
                @endforeach
            </div>

            {{-- Value proposition --}}
            <x-storefront.reveal :delay="120" class="text-center mt-10">
                <div style="background: linear-gradient(135deg, var(--x4-violet-soft), transparent); border-radius: var(--x4-r-xl); padding: 40px 24px;">
                    <p class="x4-body-lg mb-6" style="color: var(--x4-ink); font-weight: 400;">
                        {{ $mission['value_text'] }}
                    </p>

                    <div class="inline-flex items-center flex-wrap justify-center gap-3" style="background-color: var(--x4-canvas); border-radius: var(--x4-r-pill); padding: 14px 26px; box-shadow: var(--x4-shadow-2);">
                        <x-storefront.logo />
                        <span aria-hidden="true" style="color: var(--x4-hairline-warm);">|</span>
                        <span class="x4-body-md" style="color: var(--x4-violet); font-style: italic;">{{ $mission['tagline'] }}</span>
                    </div>
                </div>
            </x-storefront.reveal>
        </div>
    </section>
    @endif

    {{-- ============================================================
         Core values
         ============================================================ --}}
    @if ($cms->sectionVisible('about', 'values'))
    <section style="background-color: var(--x4-canvas); padding: 80px 0;">
        <div class="max-w-6xl mx-auto px-5">
            <x-storefront.reveal class="text-center mb-12">
                <x-storefront.eyebrow>{{ $values['eyebrow'] }}</x-storefront.eyebrow>
                <h2 class="x4-display-xl mt-4 mb-3" style="color: var(--x4-ink);">{{ $values['title'] }}</h2>
            </x-storefront.reveal>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                @foreach ($values['items'] as $i => $value)
                    <x-storefront.reveal :delay="$i * 80" class="flex">
                        <div class="text-center" style="background-color: var(--x4-canvas-soft); border: 1px solid var(--x4-hairline); border-radius: var(--x4-r-lg); padding: 32px 22px; width: 100%;">
                            <div class="mx-auto mb-5 flex items-center justify-center" style="width: 56px; height: 56px; border-radius: 9999px; background-color: var(--x4-violet-soft);">
                                <x-storefront.icon :name="$value['icon']" class="w-6 h-6" style="color: var(--x4-violet);" />
                            </div>
                            <h3 class="x4-heading-md mb-2" style="color: var(--x4-ink);">{{ $value['title'] }}</h3>
                            <p class="x4-caption" style="color: var(--x4-ink-mute);">{{ $value['body'] }}</p>
                        </div>
                    </x-storefront.reveal>
                @endforeach
            </div>
        </div>
    </section>
    @endif

    {{-- ============================================================
         Closing CTA
         ============================================================ --}}
    @if ($cms->sectionVisible('about', 'cta'))
    <section class="relative overflow-hidden" style="padding: 88px 0;">
        <div class="x4-aurora absolute inset-0" aria-hidden="true" style="opacity: 0.65;"></div>

        <div class="relative max-w-3xl mx-auto px-5 text-center">
            <x-storefront.reveal from="up">
                <h2 class="x4-display-xl mb-4" style="color: var(--x4-ink);">{{ $cta['title'] }}</h2>
                <p class="x4-body-lg mb-8" style="color: var(--x4-ink-sec);">
                    {{ $cta['description'] }}
                </p>

                <div class="flex flex-wrap gap-3 justify-center">
                    <x-storefront.btn :href="$cms->link($cta['primary_link'], $shopUrl) ?: route('storefront.index')" variant="primary">
                        {{ $cta['primary_text'] }}
                        <x-storefront.icon name="arrow" class="w-4 h-4" />
                    </x-storefront.btn>
                    <x-storefront.btn :href="$cms->link($cta['secondary_link'], $shopUrl) ?: route('vendor.request.form')" variant="outline">
                        {{ $cta['secondary_text'] }}
                    </x-storefront.btn>
                </div>
            </x-storefront.reveal>
        </div>
    </section>
    @endif
</div>
@endsection
