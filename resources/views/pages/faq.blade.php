{{--
    pages/faq.blade.php

    Public FAQ page. Questions are managed under Content > FAQs. Answers are Markdown
    that has been sanitized by CmsMarkdown, so they are printed unescaped on purpose.
--}}
@extends('layouts.app')

@section('title', 'Frequently Asked Questions - XTRA4U')
@section('description', 'Answers to common questions about buying and selling digital services on XTRA4U.')

@push('head-seo')
    <x-cms.seo :seo="['title' => 'Frequently Asked Questions - XTRA4U', 'description' => 'Answers to common questions about buying and selling digital services on XTRA4U.']" :url="route('cms.faq')" />
@endpush

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

    <x-cms.announcement-bar audience="public" />

    <section class="relative overflow-hidden" style="background: #fff;">
        <div class="x4-hero-wash absolute inset-0" aria-hidden="true" style="pointer-events: none;"></div>
        <div class="relative max-w-3xl mx-auto px-5 text-center" style="padding-top: 56px; padding-bottom: 48px;">
            <x-storefront.reveal from="up">
                <x-storefront.eyebrow>Help</x-storefront.eyebrow>
                <h1 class="x4-display-xl mt-4 mb-3" style="color: var(--x4-ink-strong);">Frequently asked questions</h1>
            </x-storefront.reveal>
        </div>
    </section>

    <section style="background-color: var(--x4-canvas-soft); padding: 8px 0 72px;">
        <div class="max-w-3xl mx-auto px-5 space-y-8">
            @foreach ($faqs as $category => $items)
                <x-storefront.reveal>
                    @if (count($faqs) > 1)
                        <h2 class="x4-heading-lg mb-3" style="color: var(--x4-ink);">{{ $category }}</h2>
                    @endif
                    <div class="space-y-3">
                        @foreach ($items as $item)
                            <details class="x4-panel group" style="padding: 0;">
                                <summary class="x4-body-md flex cursor-pointer list-none items-center justify-between gap-4" style="padding: 18px 22px; color: var(--x4-ink); font-weight: 500;">
                                    <span>{{ $item['question'] }}</span>
                                    <x-storefront.icon name="arrow" class="w-4 h-4 flex-shrink-0 transition-transform group-open:rotate-90" style="color: var(--x4-violet);" />
                                </summary>
                                <div style="padding: 0 22px 20px;">
                                    {!! \App\Support\Cms\CmsMarkdown::decorate($item['answer'], 'card', 'var(--x4-violet)') !!}
                                </div>
                            </details>
                        @endforeach
                    </div>
                </x-storefront.reveal>
            @endforeach
        </div>
    </section>
</div>
@endsection
