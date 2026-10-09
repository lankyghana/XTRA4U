{{--
    utility-bills/index.blade.php

    Global Utility Bills page (KiNG FLEXY). Served both at /services/utility-bills
    (direct XTRA4U sale) and /store/{vendor_code}/utility-bills (storefront sale).
    On a storefront it wears that store's identity (shared vendor hero, header,
    help block and the vendor store's panel / tile / pick-card / input / button
    classes) so it reads as a section of the store, not a separate site.

    Flow: choose biller -> enter details -> server-side account verification ->
    customer explicitly confirms (and, for ECG-style billers, picks a meter) ->
    amount -> pay. The browser only ever holds an opaque lookup token and a choice;
    the account, verified name, vendor attribution and limits are server-side.
--}}
@extends('layouts.app')

@section('title', $vendor ? 'Utility Bills — '.$vendor->name.' — XTRA4U Vendor Store' : 'Utility Bills - Electricity, Water & TV - XTRA4U')
@section('description', 'Pay electricity, water and TV bills'.($vendor ? ' with '.$vendor->name : '').' on XTRA4U. Verify the account first, then pay securely with Mobile Money.')

@section('body-class', 'x4')

@section('site-header')
    <x-storefront.header :shop-url="$shopUrl" :show-dashboard-button="$isStoreOwner ?? false" />
@endsection

@section('site-footer')
    <x-storefront.footer :shop-url="$shopUrl" :show-vendor-dashboard-link="false" />
@endsection

@section('content')
<script>
    window.utilityBillsConfig = {!! \Illuminate\Support\Js::from([
        'billers' => $billers,
        'limits' => $limits,
        'routes' => $routes,
        'requiresInlineMomo' => (bool) $requiresInlineMomo,
        'storeKey' => $vendor?->vendor_code ?? 'direct',
    ]) !!};
</script>

@php
    $backUrl = $vendor ? $shopUrl : route('storefront.index');
    $backLabel = $vendor ? 'Back to '.$vendor->name : 'Back to XTRA4U';
    $steps = ['Bill', 'Details', 'Confirm', 'Pay'];
    $errorText = 'color: #b91c1c;';
@endphp

<div class="x4-page" style="padding-top: 64px;" x-data="utilityBills(window.utilityBillsConfig)">
    {{-- ============================================================
         Store hero (same component as the vendor storefront)
         ============================================================ --}}
    <x-storefront.vendor-hero :vendor="$vendor" eyebrow="Utility Bills">
        <x-slot:title>
            @if ($vendor)
                Pay bills with <span style="color: var(--x4-violet);">{{ $vendor->name }}</span>
            @else
                Pay your bills, <span style="color: var(--x4-violet);">verified first</span>
            @endif
        </x-slot:title>
        Pay electricity, water and TV bills securely. We check the account with the provider before you pay.
    </x-storefront.vendor-hero>

    <div class="max-w-6xl mx-auto px-5" style="padding-top: 24px; padding-bottom: 72px;">

        {{-- Back to the store / breadcrumb --}}
        <nav aria-label="Breadcrumb" class="flex flex-wrap items-center gap-x-2 gap-y-1 mb-5 x4-caption">
            <a href="{{ $backUrl }}" class="inline-flex items-center gap-1.5" style="color: var(--x4-violet); font-weight: 500; text-decoration: none;">
                <x-storefront.icon name="back" class="w-4 h-4" />
                {{ $backLabel }}
            </a>
            <span aria-hidden="true" style="color: var(--x4-ink-mute);">/</span>
            <span aria-current="page" style="color: var(--x4-ink-sec);">Utility Bills</span>
        </nav>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            {{-- ========================================================
                 Purchase flow
                 ======================================================== --}}
            <div class="x4-panel lg:col-span-2 p-5 sm:p-6 min-w-0">

                {{-- Progress --}}
                <ol class="grid grid-cols-4 gap-2 mb-6" aria-label="Progress">
                    @foreach ($steps as $i => $label)
                        @php $n = $i + 1; @endphp
                        <li class="flex flex-col items-center gap-1.5 text-center min-w-0" :aria-current="step === {{ $n }} ? 'step' : null">
                            <span class="flex items-center justify-center x4-caption x4-tnum"
                                  style="width: 30px; height: 30px; border-radius: 9999px; font-weight: 500; transition: background-color .15s ease, color .15s ease;"
                                  :style="step >= {{ $n }}
                                      ? { backgroundColor: 'var(--x4-violet)', color: '#fff', border: '1px solid var(--x4-violet)' }
                                      : { backgroundColor: 'var(--x4-canvas-soft)', color: 'var(--x4-ink-mute)', border: '1px solid var(--x4-hairline)' }">
                                <span x-show="step <= {{ $n }}">{{ $n }}</span>
                                <span x-show="step > {{ $n }}" x-cloak><x-storefront.icon name="check" class="w-3.5 h-3.5" /></span>
                            </span>
                            <span class="x4-micro-cap truncate max-w-full" style="text-transform: none;"
                                  :style="step >= {{ $n }} ? { color: 'var(--x4-ink)', fontWeight: 500 } : { color: 'var(--x4-ink-mute)', fontWeight: 400 }">{{ $label }}</span>
                        </li>
                    @endforeach
                </ol>

                {{-- Error banner --}}
                <div x-show="error" x-cloak role="alert" class="x4-caption mb-5 flex items-start gap-2"
                     style="background-color: #fef2f2; color: #991b1b; border: 1px solid #fecaca; border-radius: var(--x4-r-md); padding: 12px 14px;">
                    <x-storefront.icon name="close" class="w-4 h-4 flex-shrink-0 mt-px" />
                    <span x-text="error"></span>
                </div>

                {{-- ---------- Step 1: biller ---------- --}}
                <div x-show="step === 1">
                    <h2 class="x4-heading-lg mb-1" style="color: var(--x4-ink);">Select a biller</h2>
                    <p class="x4-body-md mb-5" style="color: var(--x4-ink-mute);">Choose the bill you want to pay.</p>

                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 sm:gap-4">
                        <template x-for="b in billers" :key="b.key">
                            <button type="button" @click="chooseBiller(b)"
                                    :class="biller && biller.key === b.key ? 'x4-cat-tile is-selected' : 'x4-cat-tile'"
                                    class="x4-cat-tile">
                                <span class="x4-cat-icon">
                                    <span x-show="kind(b) === 'power'"><x-storefront.icon name="bolt" class="w-5 h-5" /></span>
                                    <span x-show="kind(b) === 'water'" x-cloak><x-storefront.icon name="droplet" class="w-5 h-5" /></span>
                                    <span x-show="kind(b) === 'tv'" x-cloak><x-storefront.icon name="tv" class="w-5 h-5" /></span>
                                    <span x-show="kind(b) === 'other'" x-cloak><x-storefront.icon name="receipt" class="w-5 h-5" /></span>
                                </span>
                                <span class="x4-caption" style="font-weight: 500; color: var(--x4-ink);" x-text="b.label"></span>
                                <span class="x4-micro-cap" style="color: var(--x4-ink-mute);" x-text="kindLabel(b)"></span>
                            </button>
                        </template>
                    </div>
                </div>

                {{-- ---------- Step 2: details ---------- --}}
                <form x-show="step === 2" x-cloak @submit.prevent="doLookup()" novalidate>
                    <div class="flex items-center justify-between gap-3 mb-4">
                        <h2 class="x4-heading-lg" style="color: var(--x4-ink);">Account details</h2>
                        <button type="button" class="x4-caption" style="color: var(--x4-violet); font-weight: 500; background: none; border: none; cursor: pointer;" @click="reset()">Change bill</button>
                    </div>

                    <div class="x4-pick-card is-selected mb-5" style="cursor: default; align-items: center;">
                        <span class="x4-cat-icon" style="background-color: var(--x4-violet); color: #fff;">
                            <span x-show="biller && kind(biller) === 'power'"><x-storefront.icon name="bolt" class="w-5 h-5" /></span>
                            <span x-show="biller && kind(biller) === 'water'" x-cloak><x-storefront.icon name="droplet" class="w-5 h-5" /></span>
                            <span x-show="biller && kind(biller) === 'tv'" x-cloak><x-storefront.icon name="tv" class="w-5 h-5" /></span>
                            <span x-show="biller && kind(biller) === 'other'" x-cloak><x-storefront.icon name="receipt" class="w-5 h-5" /></span>
                        </span>
                        <span class="min-w-0">
                            <span class="block x4-caption truncate" style="color: var(--x4-ink); font-weight: 500;" x-text="biller?.label"></span>
                            <span class="block x4-micro-cap mt-0.5" style="color: var(--x4-ink-mute); text-transform: none;" x-text="biller ? kindLabel(biller) : ''"></span>
                        </span>
                    </div>

                    <template x-if="biller && biller.lookup_by !== 'phone'">
                        <div class="mb-4">
                            <label for="ub-account" class="x4-caption block mb-1.5" style="color: var(--x4-ink-mute);" x-text="biller.account_label"></label>
                            <input id="ub-account" type="text" x-model.trim="account" maxlength="30" inputmode="text" autocomplete="off" required
                                   class="x4-input" :style="{ borderColor: fieldErrors.account ? '#f87171' : '' }"
                                   :aria-invalid="fieldErrors.account ? 'true' : 'false'" aria-describedby="ub-account-err">
                            <p id="ub-account-err" class="x4-caption mt-1.5" style="{{ $errorText }}" x-show="fieldErrors.account" x-text="fieldErrors.account"></p>
                        </div>
                    </template>

                    <template x-if="biller && (biller.requires_phone || biller.lookup_by === 'phone')">
                        <div class="mb-4">
                            <label for="ub-phone" class="x4-caption block mb-1.5" style="color: var(--x4-ink-mute);" x-text="biller.lookup_by === 'phone' ? 'Phone number linked to the meter' : 'Phone number'"></label>
                            <input id="ub-phone" type="tel" x-model.trim="phone" maxlength="20" inputmode="tel" autocomplete="tel" required placeholder="e.g. 0244123456"
                                   class="x4-input" :style="{ borderColor: fieldErrors.phone ? '#f87171' : '' }"
                                   :aria-invalid="fieldErrors.phone ? 'true' : 'false'" aria-describedby="ub-phone-err">
                            <p id="ub-phone-err" class="x4-caption mt-1.5" style="{{ $errorText }}" x-show="fieldErrors.phone" x-text="fieldErrors.phone"></p>
                            <p class="x4-micro-cap mt-1.5" style="color: var(--x4-ink-mute); text-transform: none;" x-show="biller.lookup_by === 'phone' && !fieldErrors.phone">We will list every meter linked to this number.</p>
                        </div>
                    </template>

                    <button type="submit" :disabled="busy" class="x4-btn x4-btn-primary w-full mt-2" style="padding: 13px 22px;" :style="{ opacity: busy ? 0.7 : 1, cursor: busy ? 'wait' : '' }">
                        <svg x-show="busy" x-cloak class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span x-text="busy ? 'Verifying…' : 'Verify account'"></span>
                    </button>
                </form>

                {{-- ---------- Step 3: confirm (+ meter choice) ---------- --}}
                <div x-show="step === 3" x-cloak>
                    <div class="flex items-center justify-between gap-3 mb-4">
                        <h2 class="x4-heading-lg" style="color: var(--x4-ink);">Confirm the account</h2>
                        <button type="button" class="x4-caption" style="color: var(--x4-violet); font-weight: 500; background: none; border: none; cursor: pointer;" @click="step = 2; error = ''">Edit details</button>
                    </div>

                    <template x-if="result && result.meters.length">
                        <div>
                            <p class="x4-body-md mb-3" style="color: var(--x4-ink-sec);" x-text="result.meters.length > 1 ? 'We found ' + result.meters.length + ' meters. Select the one you want to pay for.' : 'We found this meter. Select it to continue.'"></p>
                            <div class="space-y-2.5" role="radiogroup" aria-label="Meters">
                                <template x-for="m in result.meters" :key="m.id">
                                    <label :class="meterId === m.id ? 'x4-pick-card is-selected' : 'x4-pick-card'" class="x4-pick-card">
                                        <input type="radio" name="meter" :value="m.id" x-model="meterId" class="mt-1 flex-shrink-0" style="accent-color: var(--x4-violet); width: 16px; height: 16px;">
                                        <span class="min-w-0 flex-1">
                                            <span class="block x4-caption" style="color: var(--x4-ink); font-weight: 500; overflow-wrap: anywhere;" x-text="m.name || 'Meter'"></span>
                                            <span class="block x4-micro-cap mt-0.5 x4-tnum" style="color: var(--x4-ink-mute); text-transform: none;">Meter <span x-text="m.meter_masked"></span></span>
                                        </span>
                                        <span class="text-right flex-shrink-0">
                                            <span class="block x4-micro-cap x4-tnum" x-show="m.amount_owing" style="color: var(--x4-ink-sec); text-transform: none;">Owing GH₵<span x-text="m.amount_owing"></span></span>
                                            <span class="block x4-micro-cap x4-tnum" x-show="m.account_credit" style="color: #166534; text-transform: none;">Credit GH₵<span x-text="m.account_credit"></span></span>
                                        </span>
                                    </label>
                                </template>
                            </div>
                        </div>
                    </template>

                    <template x-if="result && !result.meters.length">
                        <div style="background-color: var(--x4-canvas-cream); border-radius: var(--x4-r-md); padding: 16px;">
                            <p class="x4-micro-cap" style="color: var(--x4-ink-mute);" x-text="result.biller.label"></p>
                            <p class="x4-heading-md mt-1" style="color: var(--x4-ink); overflow-wrap: anywhere;" x-text="result.account_name || 'Account found'"></p>
                            <div class="flex justify-between gap-3 x4-caption mt-3" style="color: var(--x4-ink-mute);">
                                <span x-text="result.biller.account_label"></span>
                                <span class="x4-tnum" style="color: var(--x4-ink); font-weight: 500;" x-text="result.account_masked"></span>
                            </div>
                            <div class="flex justify-between gap-3 x4-caption mt-2" style="color: var(--x4-ink-mute);" x-show="result.bouquet">
                                <span>Bouquet</span>
                                <span style="color: var(--x4-ink); font-weight: 500;" x-text="result.bouquet"></span>
                            </div>
                            <div class="flex justify-between gap-3 x4-caption mt-2" style="color: var(--x4-ink-mute);" x-show="result.amount_owing">
                                <span>Amount due</span>
                                <span class="x4-tnum" style="color: var(--x4-ink); font-weight: 500;">GH₵<span x-text="result.amount_owing"></span></span>
                            </div>
                            <div class="flex justify-between gap-3 x4-caption mt-2" style="color: var(--x4-ink-mute);" x-show="result.account_credit">
                                <span>Account credit</span>
                                <span class="x4-tnum" style="color: #166534; font-weight: 500;">GH₵<span x-text="result.account_credit"></span></span>
                            </div>
                        </div>
                    </template>

                    <label class="flex items-start gap-3 mt-5 x4-body-md cursor-pointer" style="color: var(--x4-ink-body);">
                        <input type="checkbox" x-model="confirmed" class="mt-1 flex-shrink-0" style="accent-color: var(--x4-violet); width: 16px; height: 16px;">
                        <span>I confirm these details are correct.</span>
                    </label>

                    <button type="button" @click="step = 4" :disabled="!canContinue" class="x4-btn x4-btn-primary w-full mt-5" style="padding: 13px 22px;"
                            :style="{ opacity: canContinue ? 1 : 0.5, cursor: canContinue ? '' : 'not-allowed' }">
                        Continue
                        <x-storefront.icon name="arrow" class="w-4 h-4" />
                    </button>
                </div>

                {{-- ---------- Step 4: amount + pay ---------- --}}
                <form x-show="step === 4" x-cloak @submit.prevent="pay()" novalidate>
                    <div class="flex items-center justify-between gap-3 mb-4">
                        <h2 class="x4-heading-lg" style="color: var(--x4-ink);">Amount &amp; payment</h2>
                        <button type="button" class="x4-caption" style="color: var(--x4-violet); font-weight: 500; background: none; border: none; cursor: pointer;" @click="step = 3; error = ''">Back</button>
                    </div>

                    <div style="background-color: var(--x4-canvas-cream); border-radius: var(--x4-r-md); padding: 16px; margin-bottom: 18px;">
                        <div class="flex justify-between gap-3 x4-caption" style="color: var(--x4-ink-mute);">
                            <span>Paying</span>
                            <span class="text-right" style="color: var(--x4-ink); font-weight: 500; overflow-wrap: anywhere;" x-text="selectedName"></span>
                        </div>
                        <div class="flex justify-between gap-3 x4-caption mt-2" style="color: var(--x4-ink-mute);">
                            <span x-text="result && result.meters.length ? 'Meter' : (result ? result.biller.account_label : 'Account')"></span>
                            <span class="x4-tnum" style="color: var(--x4-ink); font-weight: 500;" x-text="selectedMasked"></span>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="ub-amount" class="x4-caption block mb-1.5" style="color: var(--x4-ink-mute);">Amount</label>
                        <div class="relative">
                            <span class="absolute x4-caption" style="left: 14px; top: 50%; transform: translateY(-50%); color: var(--x4-ink-mute); pointer-events: none;">GH₵</span>
                            <input id="ub-amount" type="text" x-model.trim="amount" inputmode="decimal" autocomplete="off" required placeholder="0.00"
                                   class="x4-input x4-tnum" style="padding-left: 46px;" :style="{ borderColor: fieldErrors.amount ? '#f87171' : '' }"
                                   :aria-invalid="fieldErrors.amount ? 'true' : 'false'" aria-describedby="ub-amount-help ub-amount-err">
                        </div>
                        <p id="ub-amount-help" class="x4-micro-cap mt-1.5" style="color: var(--x4-ink-mute); text-transform: none;" x-show="limits.min || limits.max">
                            <span x-show="limits.min">Minimum GH₵<span x-text="limits.min"></span></span>
                            <span x-show="limits.min && limits.max"> &middot; </span>
                            <span x-show="limits.max">Maximum GH₵<span x-text="limits.max"></span></span>
                        </p>
                        <p id="ub-amount-err" class="x4-caption mt-1.5" style="{{ $errorText }}" x-show="fieldErrors.amount" x-text="fieldErrors.amount"></p>
                    </div>

                    <template x-if="requiresInlineMomo">
                        <div>
                            <div class="mb-3">
                                <label for="ub-payer-phone" class="x4-caption block mb-1.5" style="color: var(--x4-ink-mute);">MoMo number</label>
                                <input id="ub-payer-phone" type="tel" x-model.trim="payerPhone" inputmode="tel" autocomplete="tel" maxlength="20" required placeholder="e.g. 0551234567" class="x4-input">
                                <p class="x4-caption mt-1.5" style="{{ $errorText }}" x-show="fieldErrors.payer_phone" x-text="fieldErrors.payer_phone"></p>
                            </div>
                            <div class="mb-4">
                                <label for="ub-payer-network" class="x4-caption block mb-1.5" style="color: var(--x4-ink-mute);">Network</label>
                                <select id="ub-payer-network" x-model="payerNetwork" required class="x4-input">
                                    <option value="">Select network</option>
                                    <option value="MTN">MTN</option>
                                    <option value="TELECEL">Telecel</option>
                                    <option value="AIRTELTIGO">AirtelTigo</option>
                                </select>
                                <p class="x4-caption mt-1.5" style="{{ $errorText }}" x-show="fieldErrors.payer_network" x-text="fieldErrors.payer_network"></p>
                            </div>
                        </div>
                    </template>

                    <p class="x4-micro-cap mb-4" style="color: var(--x4-ink-mute); text-transform: none;">You pay the bill amount. Your gateway may add its own fee at checkout.</p>

                    <button type="submit" :disabled="busy" class="x4-btn x4-btn-primary w-full" style="padding: 13px 22px;" :style="{ opacity: busy ? 0.7 : 1, cursor: busy ? 'wait' : '' }">
                        <svg x-show="busy" x-cloak class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span x-show="!busy"><x-storefront.icon name="lock" class="w-4 h-4" /></span>
                        <span x-text="busy ? (waiting ? 'Waiting for payment…' : 'Please wait…') : 'Proceed to Payment'"></span>
                    </button>
                </form>
            </div>

            {{-- ========================================================
                 Summary (mirrors the storefront Checkout panel)
                 ======================================================== --}}
            <aside class="x4-panel p-5 sm:p-6 lg:sticky lg:top-20 self-start min-w-0" aria-label="Bill summary">
                <h3 class="x4-heading-md mb-4" style="color: var(--x4-ink);">Your bill</h3>

                <div style="background-color: var(--x4-canvas-cream); border-radius: var(--x4-r-md); padding: 16px;">
                    @if ($vendor)
                        <div class="flex justify-between gap-3 x4-caption" style="color: var(--x4-ink-mute);">
                            <span>Store</span>
                            <span class="text-right" style="color: var(--x4-ink); font-weight: 500;">{{ $vendor->name }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between gap-3 x4-caption {{ $vendor ? 'mt-2' : '' }}" style="color: var(--x4-ink-mute);">
                        <span>Bill</span>
                        <span class="text-right" style="color: var(--x4-ink); font-weight: 500;" x-text="biller ? biller.label : '—'"></span>
                    </div>
                    <div class="flex justify-between gap-3 x4-caption mt-2" style="color: var(--x4-ink-mute);">
                        <span>Account</span>
                        <span class="text-right x4-tnum" style="color: var(--x4-ink); font-weight: 500; overflow-wrap: anywhere;" x-text="step >= 3 && selectedMasked ? selectedMasked : '—'"></span>
                    </div>
                    <div class="flex justify-between items-center mt-3 pt-3" style="border-top: 1px solid rgba(0,0,0,0.08);">
                        <span class="x4-body-md" style="color: var(--x4-ink);">Amount</span>
                        <span class="x4-tnum" style="font-size: 20px; font-weight: 500; color: var(--x4-violet);" x-text="displayAmount"></span>
                    </div>
                </div>

                <ul class="mt-5 space-y-3">
                    <li class="flex items-start gap-2.5 x4-caption" style="color: var(--x4-ink-sec);">
                        <x-storefront.icon name="shield" class="w-4 h-4 flex-shrink-0 mt-px" style="color: var(--x4-violet);" />
                        <span>The account is verified with the biller before you pay.</span>
                    </li>
                    <li class="flex items-start gap-2.5 x4-caption" style="color: var(--x4-ink-sec);">
                        <x-storefront.icon name="lock" class="w-4 h-4 flex-shrink-0 mt-px" style="color: var(--x4-violet);" />
                        <span>Pay securely with Mobile Money.</span>
                    </li>
                    <li class="flex items-start gap-2.5 x4-caption" style="color: var(--x4-ink-sec);">
                        <x-storefront.icon name="receipt" class="w-4 h-4 flex-shrink-0 mt-px" style="color: var(--x4-violet);" />
                        <span>You get a reference and a receipt you can come back to.</span>
                    </li>
                </ul>
            </aside>
        </div>
    </div>

    {{-- Payment confirmation overlay (same as the storefront's inline-gateway overlay) --}}
    <div x-show="waiting" x-cloak class="fixed inset-0 z-50 flex items-center justify-center" role="status" aria-live="polite">
        <div class="absolute inset-0" style="background: rgba(13,37,61,0.6); backdrop-filter: blur(4px);"></div>
        <div class="relative w-full max-w-lg mx-4" style="background-color: var(--x4-canvas); border-radius: var(--x4-r-xl); box-shadow: var(--x4-shadow-3); overflow: hidden;">
            <div class="p-6 sm:p-8">
                <div class="flex items-center gap-4">
                    <div class="relative flex-shrink-0 flex items-center justify-center" style="width: 56px; height: 56px; border-radius: var(--x4-r-lg); background-color: var(--x4-violet-soft);">
                        <x-storefront.icon name="clock" class="w-7 h-7" style="color: var(--x4-violet); opacity: 0.35;" />
                        <svg class="absolute animate-spin" style="top: -4px; left: -4px; width: 64px; height: 64px; color: var(--x4-violet);" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v3a5 5 0 00-5 5H4z"></path>
                        </svg>
                    </div>
                    <div class="flex-1">
                        <h3 class="x4-heading-md" style="color: var(--x4-ink);">Confirming payment status</h3>
                        <p class="x4-body-md mt-1" style="color: var(--x4-ink-mute);">Approve the Mobile Money prompt on your phone. This usually takes a moment.</p>
                    </div>
                </div>
                <div class="mt-6" style="background-color: var(--x4-canvas-soft); border: 1px solid var(--x4-hairline); border-radius: var(--x4-r-md); padding: 16px;">
                    <p class="x4-caption" style="color: var(--x4-ink); font-weight: 500;">Don't close or refresh this page</p>
                    <p class="x4-caption mt-0.5" style="color: var(--x4-ink-sec);">You will be taken to your receipt automatically once payment is confirmed.</p>
                </div>
                <div class="mt-6" style="height: 6px; width: 100%; border-radius: 9999px; background-color: var(--x4-canvas-soft); overflow: hidden;">
                    <div class="animate-pulse" style="height: 100%; width: 33%; border-radius: 9999px; background-color: var(--x4-violet);"></div>
                </div>
            </div>
        </div>
    </div>

    @if ($vendor)
        <x-storefront.vendor-help :vendor="$vendor" :message="'Contact '.$vendor->name.' if you have a question about your bill payment.'" />
    @endif
</div>

<script>
    function utilityBills(cfg) {
        const csrf = () => (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
        return {
            billers: cfg.billers, limits: cfg.limits || {}, routes: cfg.routes,
            requiresInlineMomo: !!cfg.requiresInlineMomo, storeKey: cfg.storeKey,
            step: 1, biller: null, account: '', phone: '', result: null, token: null,
            meterId: '', confirmed: false, amount: '', payerPhone: '', payerNetwork: '',
            busy: false, waiting: false, error: '', fieldErrors: {}, intentKey: null,

            // Presentation only: icon + subtitle for a biller tile.
            kind(b) {
                const s = ((b && b.key) || '') + ' ' + ((b && b.label) || '').toLowerCase();
                if (b && b.lookup_by === 'phone' || /ecg|electric|power|nedco|prepaid/.test(s)) return 'power';
                if (/water/.test(s)) return 'water';
                if (/tv|dstv|gotv|startimes|cable|satellite/.test(s)) return 'tv';
                return 'other';
            },
            kindLabel(b) {
                return {power: 'Electricity', water: 'Water', tv: 'TV subscription', other: 'Bill payment'}[this.kind(b)];
            },
            get displayAmount() {
                const n = parseFloat(this.amount);
                return this.step >= 3 && !isNaN(n) && n > 0 ? 'GH₵' + n.toFixed(2) : '—';
            },

            get canContinue() {
                if (!this.result) return false;
                if (this.result.meters.length && !this.meterId) return false;
                return this.confirmed;
            },
            get selectedName() {
                if (this.result && this.result.meters.length) {
                    const m = this.result.meters.find(x => x.id === this.meterId);
                    return m ? (m.name || 'Meter') : '';
                }
                return this.result ? (this.result.account_name || this.result.biller.label) : '';
            },
            get selectedMasked() {
                if (this.result && this.result.meters.length) {
                    const m = this.result.meters.find(x => x.id === this.meterId);
                    return m ? m.meter_masked : '';
                }
                return this.result ? this.result.account_masked : '';
            },

            chooseBiller(b) { this.biller = b; this.step = 2; this.error = ''; this.fieldErrors = {}; },
            reset() {
                this.step = 1; this.biller = null; this.account = ''; this.phone = ''; this.result = null;
                this.token = null; this.meterId = ''; this.confirmed = false; this.amount = ''; this.error = ''; this.fieldErrors = {};
            },

            async post(url, body) {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest'},
                    body: JSON.stringify(body),
                });
                let data = {};
                try { data = await res.json(); } catch (e) {}
                return {ok: res.ok, status: res.status, data};
            },
            applyErrors(r) {
                this.fieldErrors = {};
                if (r.status === 422 && r.data.errors) {
                    Object.keys(r.data.errors).forEach(k => this.fieldErrors[k] = r.data.errors[k][0]);
                    this.error = r.data.errors.lookup_token ? r.data.errors.lookup_token[0] : '';
                    if (!Object.keys(this.fieldErrors).length) this.error = r.data.message || 'Please check your details.';
                } else {
                    this.error = r.data.message || 'Something went wrong. Please try again.';
                }
            },

            async doLookup() {
                if (this.busy) return;
                this.busy = true; this.error = ''; this.fieldErrors = {};
                try {
                    const r = await this.post(this.routes.lookup, {biller: this.biller.key, account: this.account, phone: this.phone});
                    if (!r.ok || !r.data.success) { this.applyErrors(r); return; }
                    this.result = r.data; this.token = r.data.token; this.meterId = ''; this.confirmed = false;
                    this.amount = r.data.amount_owing && !r.data.meters.length ? r.data.amount_owing : '';
                    this.step = 3;
                } catch (e) { this.error = 'Network problem. Please try again.'; }
                finally { this.busy = false; }
            },

            async pay() {
                if (this.busy) return;
                this.busy = true; this.error = ''; this.fieldErrors = {};
                const fp = ['ub', this.storeKey, this.token, this.meterId, this.amount].join(':');
                this.intentKey = window.XtraCheckoutIntent ? window.XtraCheckoutIntent.getKey(fp) : null;
                try {
                    const r = await this.post(this.routes.checkout, {
                        lookup_token: this.token, selected_meter_id: this.meterId || null, amount: this.amount,
                        payer_phone: this.payerPhone || null, payer_network: this.payerNetwork || null,
                        idempotency_key: this.intentKey,
                    });
                    if (!r.ok || !r.data.success) { this.busy = false; this.applyErrors(r); return; }
                    const d = r.data;
                    if (d.status === 'success' && d.redirect) { window.location.href = d.redirect; return; }
                    if (d.flow_type === 'payaza' && d.checkout_config && window.InlinePaymentManager) {
                        window.InlinePaymentManager.openPayaza({reference: d.reference, checkout_config: d.checkout_config, verify_url: d.verify_url}, (s) => {
                            if (s !== 'paid') this.busy = false;
                        });
                        return;
                    }
                    if (d.redirect && !this.requiresInlineMomo) { window.location.href = d.redirect; return; }
                    if (d.reference) { this.waiting = true; this.poll(d.reference, d.verify_url, d.status_url); return; }
                    this.busy = false; this.error = 'Could not start the payment. Please try again.';
                } catch (e) { this.busy = false; this.error = 'Network problem. Please try again.'; }
            },

            poll(reference, verifyUrl, statusUrl) {
                let n = 0;
                const tick = async () => {
                    n++;
                    const r = await this.post(verifyUrl, {reference});
                    const s = r.data && r.data.status;
                    if (s === 'success' && r.data.redirect) { window.location.href = r.data.redirect; return; }
                    if (s === 'failed') {
                        if (window.XtraCheckoutIntent) window.XtraCheckoutIntent.clear(['ub', this.storeKey, this.token, this.meterId, this.amount].join(':'));
                        this.busy = false; this.waiting = false; this.error = r.data.message || 'Payment failed. Please try again.'; return;
                    }
                    if (n >= 60) { if (statusUrl) window.location.href = statusUrl; return; }
                    setTimeout(tick, 3000);
                };
                setTimeout(tick, 3000);
            },
        };
    }
</script>
@endsection
