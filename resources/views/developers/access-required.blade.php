@extends('layouts.developers')
@section('title', 'Vendor approval required - XTRA4U')

@section('page')
    <section class="relative overflow-hidden" style="background: #fff; min-height: 60vh;">
        <div class="x4-hero-wash absolute inset-0" aria-hidden="true" style="pointer-events: none;"></div>
        <div class="relative max-w-2xl mx-auto px-5 text-center" style="padding-top: 72px; padding-bottom: 72px;">
            <x-storefront.eyebrow>Developer API</x-storefront.eyebrow>
            <h1 class="x4-display-xl mt-4 mb-4" style="color: var(--x4-ink-strong);">Vendor approval required</h1>
            <div class="x4-panel text-left" style="padding: 28px;" role="status">
                <p class="x4-body-md mb-3" style="color: var(--x4-ink);">The Developer API is available to approved XTRA4U vendors. Your vendor account <b>{{ $vendor->name }}</b> is not currently approved, so API access is switched off: you can't create applications or keys, and any existing keys and webhooks are not active.</p>
                <p class="x4-body-md" style="color: var(--x4-ink-sec);">Access returns as soon as an administrator approves your vendor account. Keys that were revoked while the account was not approved stay revoked, so you will generate new ones afterwards.</p>
            </div>
            <div class="mt-8">
                <x-storefront.btn :href="route('developers.landing')" variant="outline" :hero="true">About the API</x-storefront.btn>
            </div>
        </div>
    </section>
@endsection
