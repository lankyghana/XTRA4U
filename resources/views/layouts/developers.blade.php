{{--
    Public-facing Developer API pages (landing, "approval required"): the public storefront shell, exactly like
    /about and the other marketing pages. The signed-in portal pages (dashboard, applications, docs, ...) do NOT use
    this: they sit inside the vendor dashboard via <x-developers.shell>.

    Child views define @section('page') (and @section('title')).
--}}
@extends('layouts.app')

@push('head-seo')
    <meta name="robots" content="noindex">
@endpush

@section('body-class', 'x4')

@php
    // Same "Shop" target as the other public pages when the flagship store is configured. Deliberately NOT
    // MainStore::vendor(): its fallback picks an arbitrary approved vendor, and this page must never carry
    // vendor-specific data (only the configured, already-public flagship code can appear).
    // The configured code is only used when that vendor really exists here (it can be absent in local/staging data).
    $mainStoreCode = config('storefront.main_store_code');
    $shopUrl = $mainStoreCode && \App\Models\Vendor::where('vendor_code', $mainStoreCode)->exists()
        ? route('storefront.vendor', ['vendor' => $mainStoreCode])
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
        @yield('page')
    </div>
@endsection
