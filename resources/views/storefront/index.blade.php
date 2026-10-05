@extends('layouts.app')

@php $seo = $cms->structuredSeo('home'); @endphp
@section('title', $seo['title'])
@section('description', $seo['description'])
@push('head-seo')
    <x-cms.seo :seo="$seo" :url="url('/')" />
@endpush

{{-- Scope the storefront design system to this page only. --}}
@section('body-class', 'x4')

@php
    /*
     * Every call to action on this page routes into the existing storefront.
     * `MainStore::vendor()` (supplied by StorefrontController@index) resolves
     * the flagship vendor; when no vendor exists we fall back to the
     * marketplace, exactly as this page did before the redesign.
     */
    $shopUrl = $mainStore
        ? route('storefront.vendor', ['vendor' => $mainStore->vendor_code])
        : route('checkout.show');

    /*
     * The service grid is driven by the platform's own category
     * configuration, so it reflects whatever the application actually
     * offers rather than a fixed list copied from the design.
     */
    $categories = config('storefront.categories', []);

    // config/storefront.php icon names -> this page's inline SVG set.
    $iconMap = [
        'signal' => 'wifi',
        'bolt' => 'zap',
        'bag' => 'ticket',
        'chart' => 'grad',
        'clipboard' => 'id',
    ];

    $badgeMap = [
        'data' => 'Most Popular',
        'results' => 'Bulk Available',
    ];

    /*
     * Every configured category now has a dedicated platform page
     * (App\Http\Controllers\PlatformServiceController). Each one resolves
     * its own admin-assigned vendor (App\Support\PlatformServiceVendor) and
     * degrades gracefully if unconfigured/unavailable, so this card grid
     * doesn't need to check availability itself — unlike the old AFA-only
     * check this replaced, which had to because it linked straight to a
     * specific vendor's form.
     */
    $categoryRoutes = [
        'data' => 'services.data-bundles',
        'ecg' => 'services.ecg',
        'shop' => 'services.shop',
        'results' => 'result-checkers.entry',
        'afa' => 'services.afa-registration',
    ];

    $serviceCards = collect($categories)->map(function ($category, $key) use ($shopUrl, $iconMap, $badgeMap, $categoryRoutes) {
        // A category without a dedicated route yet falls back to the
        // flagship store, exactly as every card did before this feature.
        $href = isset($categoryRoutes[$key]) ? route($categoryRoutes[$key]) : $shopUrl;

        return [
            'name' => $category['label'] ?? Str::headline($key),
            'description' => $category['description'] ?? null,
            'icon' => $iconMap[$category['icon'] ?? ''] ?? 'wifi',
            'badge' => $badgeMap[$key] ?? null,
            'href' => $href,
        ];
    })->values();


    $paymentNetworks = [
        ['src' => asset('images/storefront/pay-mtn-momo.png'), 'alt' => 'MTN Mobile Money', 'bg' => '#1B4F72'],
        ['src' => asset('images/storefront/pay-telecel-cash.jpg'), 'alt' => 'Telecel Cash', 'bg' => '#ffffff'],
        ['src' => asset('images/storefront/pay-airteltigo-money.png'), 'alt' => 'AirtelTigo Money', 'bg' => '#ffffff'],
    ];
@endphp

@section('site-header')
    <x-storefront.header :shop-url="$shopUrl" :show-vendor-links="true" />
@endsection

@section('site-footer')
    <x-storefront.footer :shop-url="$shopUrl" :show-vendor-links="true" />
@endsection

@section('content')
{{-- The header is fixed at 64px tall; offset the page beneath it. --}}
<div class="x4-page" style="padding-top: 64px;">

    <x-cms.announcement-bar audience="public" />

    {{-- The hero (with its trust strip) is always first; the remaining sections follow the
         order and visibility set under Content > Homepage. Content for every section comes
         from the CMS (see App\Support\Cms\CmsRegistry for the defaults). --}}
    @include('storefront.home.hero')

    @foreach ($cms->movableOrder('home') as $sectionKey)
        @include('storefront.home.'.$sectionKey)
    @endforeach
</div>
@endsection
