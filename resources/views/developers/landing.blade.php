@extends('layouts.developers')
@section('title', 'XTRA4U API')
@section('content')
<div class="max-w-3xl mx-auto">
    <section class="text-center py-10">
        <h1 class="text-3xl font-semibold mb-3">The XTRA4U API for vendors</h1>
        <p class="text-gray-600 mb-6">Sell your XTRA4U products from your own website, mobile app or platform. The API is open for anyone to read about, and available to <b>approved XTRA4U vendors</b>.</p>

        @if($state === 'approved')
            <a href="{{ route('developers.dashboard') }}" class="inline-block rounded-lg bg-[#00942C] text-white px-5 py-2.5 font-medium">Open your Developer Portal</a>
        @elseif($state === 'pending')
            <div class="inline-block rounded-lg border border-amber-300 bg-amber-50 px-5 py-3 text-sm text-amber-900">Your vendor account is awaiting approval. API access switches on as soon as an administrator approves it.</div>
        @else
            <div class="flex justify-center gap-3 flex-wrap">
                <a href="{{ route('vendor.request.form') }}" class="rounded-lg bg-[#00942C] text-white px-5 py-2.5 font-medium">Become a vendor</a>
                <a href="{{ route('vendor.login.form') }}" class="rounded-lg border border-gray-300 bg-white px-5 py-2.5 font-medium">Vendor sign in</a>
            </div>
        @endif
    </section>

    <section class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
        <h2 class="font-semibold mb-2">What the API offers</h2>
        <ul class="text-sm text-gray-700 list-disc ml-5 space-y-1">
            <li>Browse <b>your own</b> catalog and live prices, request a price quote, place an order and start its payment.</li>
            <li>Follow order and payment status, or receive <b>signed webhooks</b> when an order or payment changes.</li>
            <li>Orders you place through the API are <b>your store's orders</b>: pricing, earnings and commissions are exactly the same as on your storefront. The API adds no fees or markups.</li>
            <li>Your applications can only ever see and sell <b>your</b> products. No other vendor's data is reachable.</li>
        </ul>
    </section>

    <section class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
        <h2 class="font-semibold mb-2">Available services</h2>
        <ul class="text-sm text-gray-700 space-y-1">
            @foreach($services as $svc)
                <li><b>{{ $svc['name'] }}</b>@if($svc['description']) — {{ $svc['description'] }}@endif
                    <span class="text-xs rounded-full px-2 py-0.5 {{ $svc['available'] ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' }}">{{ $svc['available'] ? 'open' : 'currently closed' }}</span></li>
            @endforeach
        </ul>
        <p class="text-xs text-gray-500 mt-2">Other XTRA4U services are not part of API v1.</p>
    </section>

    <section class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
        <h2 class="font-semibold mb-2">How to get access</h2>
        <ol class="text-sm text-gray-700 list-decimal ml-5 space-y-1">
            <li>Apply on XTRA4U through the normal <a class="underline" href="{{ route('vendor.request.form') }}">vendor application</a>.</li>
            <li>An XTRA4U administrator reviews and approves your vendor account.</li>
            <li>Sign in with your vendor account and open the Developer Portal here. There is no separate developer login.</li>
            <li>Create an application and generate an API key. The full key is shown once, so store it safely.</li>
        </ol>
        <p class="text-xs text-gray-500 mt-2">Pending, rejected or suspended vendors cannot use the API. If your vendor account loses approval, your API keys are revoked and your webhooks disabled straight away.</p>
    </section>

    <section class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
        <h2 class="font-semibold mb-2">Authentication</h2>
        <p class="text-sm text-gray-700 mb-2">Send your key as a bearer token over HTTPS. Keys look like <code>xt_live_…</code> (production) or <code>xt_test_…</code> (test). Each key has scopes and an expiry, and requests are rate limited per application.</p>
        <pre class="text-xs bg-gray-900 text-gray-100 rounded-lg p-3 overflow-x-auto">curl {{ url('/api/v1/products') }} \
  -H "Authorization: Bearer xt_live_yourkey"</pre>
    </section>

    <section class="bg-white rounded-xl border border-gray-200 p-6">
        <h2 class="font-semibold mb-2">Integration steps</h2>
        <ol class="text-sm text-gray-700 list-decimal ml-5 space-y-1">
            <li><code>GET /api/v1/products</code> — list your offers and prices.</li>
            <li><code>POST /api/v1/quotes</code> — freeze a price for a recipient number.</li>
            <li><code>POST /api/v1/orders</code> — create the order from the quote (send an <code>Idempotency-Key</code>).</li>
            <li><code>POST /api/v1/orders/{id}/payments</code> — start payment and send the customer to the returned link.</li>
            <li>Track the result by polling <code>/api/v1/orders/{id}/status</code> or by receiving signed webhooks.</li>
        </ol>
        <p class="text-xs text-gray-500 mt-2">Full reference documentation will be published separately.</p>
    </section>
</div>
@endsection
