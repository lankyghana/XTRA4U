{{--
    Customer status page / receipt for a Utility Bill. Reached by an opaque token
    (never an account number). Shows only masked identifiers. The headline says
    "successful" only once the provider has reported the bill completed.
    When the bill was bought on a vendor storefront it keeps that store's
    header links, "back to store" navigation and help block.
--}}
@extends('layouts.app')

@section('title', 'Utility Bill '.$view['reference'].($vendor ? ' — '.$vendor->name : '').' - XTRA4U')
@section('body-class', 'x4')

@section('site-header')
    <x-storefront.header :shop-url="$shopUrl" />
@endsection

@section('site-footer')
    <x-storefront.footer :shop-url="$shopUrl" :show-vendor-dashboard-link="false" />
@endsection

@section('content')
@php
    $backUrl = $vendor ? $shopUrl : route('storefront.index');
    $backLabel = $vendor ? 'Back to '.$vendor->name : 'Back to XTRA4U';
@endphp
<div class="x4-page" style="padding-top: 64px;"
     x-data="{ v: @js($view), async refresh() { if (this.v.terminal) return; try { const r = await fetch('{{ route('utility-bills.poll', ['token' => $u->access_token]) }}', {headers:{Accept:'application/json'}}); if (r.ok) this.v = await r.json(); } catch (e) {} if (!this.v.terminal) setTimeout(() => this.refresh(), 5000); } }"
     x-init="setTimeout(() => refresh(), 5000)">

    <section class="relative overflow-hidden" style="background: #fff;">
        <div class="x4-hero-wash absolute inset-0" aria-hidden="true" style="pointer-events: none;"></div>
        <div class="relative max-w-2xl mx-auto px-5" style="padding-top: 24px; padding-bottom: 72px;">

            <nav aria-label="Breadcrumb" class="flex flex-wrap items-center gap-x-2 gap-y-1 mb-5 x4-caption print:hidden">
                <a href="{{ $backUrl }}" class="inline-flex items-center gap-1.5" style="color: var(--x4-violet); font-weight: 500; text-decoration: none;">
                    <x-storefront.icon name="back" class="w-4 h-4" />
                    {{ $backLabel }}
                </a>
                <span aria-hidden="true" style="color: var(--x4-ink-mute);">/</span>
                <a href="{{ $payAnotherUrl }}" style="color: var(--x4-ink-sec); text-decoration: none;">Utility Bills</a>
                <span aria-hidden="true" style="color: var(--x4-ink-mute);">/</span>
                <span aria-current="page" style="color: var(--x4-ink-sec);">Receipt</span>
            </nav>

            <div class="x4-panel p-5 sm:p-8">
                <div role="status" aria-live="polite" class="flex items-start gap-4">
                    {{-- Stage icon --}}
                    <div class="relative flex-shrink-0 flex items-center justify-center" style="width: 56px; height: 56px; border-radius: var(--x4-r-lg);"
                         :style="v.stage === 'completed' ? { backgroundColor: '#dcfce7', color: '#16a34a' }
                               : (['payment_failed', 'needs_support'].includes(v.stage) ? { backgroundColor: '#fee2e2', color: '#dc2626' }
                               : { backgroundColor: 'var(--x4-violet-soft)', color: 'var(--x4-violet)' })">
                        <span x-show="v.stage === 'completed'"><x-storefront.icon name="check" class="w-7 h-7" /></span>
                        <span x-show="['payment_failed', 'needs_support'].includes(v.stage)" x-cloak><x-storefront.icon name="close" class="w-7 h-7" /></span>
                        <span x-show="!v.terminal || v.stage === 'delayed'" x-cloak>
                            <x-storefront.icon name="clock" class="w-7 h-7" style="opacity: 0.35;" />
                            <svg x-show="!v.terminal" class="absolute animate-spin" style="top: -4px; left: -4px; width: 64px; height: 64px;" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v3a5 5 0 00-5 5H4z"></path>
                            </svg>
                        </span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <x-storefront.eyebrow>Utility Bills</x-storefront.eyebrow>
                        <h1 class="x4-heading-lg mt-2" style="color: var(--x4-ink);" x-text="v.headline">{{ $view['headline'] }}</h1>
                        <p class="x4-body-md mt-1" style="color: var(--x4-ink-sec);" x-text="v.detail">{{ $view['detail'] }}</p>
                    </div>
                </div>

                <dl class="mt-6" style="background-color: var(--x4-canvas-cream); border-radius: var(--x4-r-md); padding: 16px;">
                    @if ($vendor)
                        <div class="flex justify-between gap-4 x4-caption py-1.5">
                            <dt style="color: var(--x4-ink-mute);">Store</dt>
                            <dd class="text-right" style="color: var(--x4-ink); font-weight: 500;">{{ $vendor->name }}</dd>
                        </div>
                    @endif
                    @foreach ([
                        ['Reference', 'reference'], ['Bill', 'biller'], ['Account', 'account_masked'],
                        ['Account name', 'account_name'], ['Status', 'status_label'], ['Date', 'date'],
                    ] as [$label, $key])
                        <div class="flex justify-between gap-4 x4-caption py-1.5" x-show="v['{{ $key }}']">
                            <dt style="color: var(--x4-ink-mute);">{{ $label }}</dt>
                            <dd class="text-right {{ in_array($key, ['reference', 'account_masked'], true) ? 'x4-tnum' : '' }}" style="color: var(--x4-ink); font-weight: 500; overflow-wrap: anywhere;" x-text="v['{{ $key }}']"></dd>
                        </div>
                    @endforeach
                    <div class="flex justify-between items-center gap-4 mt-2 pt-3" style="border-top: 1px solid rgba(0,0,0,0.08);" x-show="v.amount">
                        <dt class="x4-body-md" style="color: var(--x4-ink);">Amount</dt>
                        <dd class="x4-tnum" style="font-size: 20px; font-weight: 500; color: var(--x4-violet);" x-text="(v.currency === 'GHS' ? 'GH₵' : v.currency + ' ') + v.amount"></dd>
                    </div>
                </dl>

                <div class="mt-6 flex flex-col sm:flex-row gap-3 print:hidden">
                    <button type="button" class="x4-btn x4-btn-primary sm:flex-1" style="padding: 13px 22px;" x-show="v.stage === 'completed'" x-cloak onclick="window.print()">
                        <x-storefront.icon name="receipt" class="w-4 h-4" />
                        Print receipt
                    </button>
                    <a href="{{ $payAnotherUrl }}" class="x4-btn x4-btn-outline sm:flex-1" style="padding: 13px 22px;">Pay another bill</a>
                </div>
                <p class="x4-micro-cap mt-4" style="color: var(--x4-ink-mute); text-transform: none;">Keep your reference. Quote it if you need help.</p>
            </div>
        </div>
    </section>

    @if ($vendor)
        <div class="print:hidden">
            <x-storefront.vendor-help :vendor="$vendor" :message="'Contact '.$vendor->name.' and quote your reference if you need help with this bill.'" />
        </div>
    @endif
</div>
@endsection
