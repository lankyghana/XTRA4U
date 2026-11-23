@extends('layouts.vendor')
@section('title', 'Webhook secret - XTRA4U')
@section('content')
<x-developers.shell :title="'Webhook signing secret '.$verb" subtitle="Copy it now: it is shown only once" tab="applications">
    <x-developers.section tone="secret" class="max-w-3xl">
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 mb-4" role="alert">
            Copy this secret now. <b>It will not be shown again.</b> It is stored encrypted and used to sign every delivery to <span class="break-all">{{ $webhook->url }}</span>.
        </div>

        <x-developers.secret id="new-webhook-secret" label="Signing secret" :value="$secret" />

        <p class="text-xs text-gray-500 mt-4">Each request carries <code class="bg-gray-100 rounded px-1">X-XTRA4U-Signature: t=&lt;unix&gt;,v1=&lt;hmac&gt;</code>, where <code class="bg-gray-100 rounded px-1">hmac = HMAC-SHA256(secret, "&lt;t&gt;.&lt;raw body&gt;")</code>. Verify against the raw body, compare in constant time, and reject timestamps older than 5 minutes. De-duplicate on the event <code class="bg-gray-100 rounded px-1">id</code>. See <a class="text-brand-violet underline" href="{{ route('developers.docs.show', 'quickstart') }}#content-receive-webhooks">Receive webhooks</a> for working code.</p>
        <x-button :href="route('developers.webhooks.index', $app)" class="mt-4">I've saved it &mdash; continue</x-button>
    </x-developers.section>
</x-developers.shell>
@endsection
