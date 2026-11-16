@extends('layouts.developers')
@section('title', 'Webhook secret')
@section('content')
<div class="max-w-2xl bg-white rounded-xl border border-amber-300 p-6">
    <h1 class="text-xl font-semibold mb-2">Webhook signing secret {{ $verb }}</h1>
    <p class="text-sm text-amber-800 mb-4">Copy this secret now. <b>It will not be shown again.</b> It is stored encrypted and used to sign every delivery to <span class="break-all">{{ $webhook->url }}</span>.</p>
    <code id="new-webhook-secret" class="block break-all rounded-lg bg-gray-900 text-green-300 px-4 py-3 text-sm select-all">{{ $secret }}</code>
    <p class="text-xs text-gray-500 mt-4">Each request carries <code>X-XTRA4U-Signature: t=&lt;unix&gt;,v1=&lt;hmac&gt;</code>, where <code>hmac = HMAC-SHA256(secret, "&lt;t&gt;.&lt;raw body&gt;")</code>. Verify against the raw body, compare in constant time, and reject timestamps older than 5 minutes. De-duplicate on the event <code>id</code>.</p>
    <a href="{{ route('developers.webhooks.index', $app) }}" class="inline-block mt-4 rounded-lg bg-gray-900 text-white px-4 py-2 text-sm">I've saved it — continue</a>
</div>
@endsection
