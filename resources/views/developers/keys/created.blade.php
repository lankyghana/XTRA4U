@extends('layouts.developers')
@section('title', 'API key '.$verb)
@section('content')
<div class="max-w-2xl bg-white rounded-xl border border-amber-300 p-6">
    <h1 class="text-xl font-semibold mb-2">API key {{ $verb }}</h1>
    <p class="text-sm text-amber-800 mb-4">Copy this key now. <b>It will not be shown again</b> — XTRA4U stores only a one-way hash, so a lost key can't be recovered, only replaced.</p>
    <code id="new-api-key" class="block break-all rounded-lg bg-gray-900 text-green-300 px-4 py-3 text-sm select-all">{{ $plaintext }}</code>
    <dl class="mt-4 text-sm grid grid-cols-2 gap-2">
        <div><dt class="text-gray-500 inline">Name:</dt> <dd class="inline">{{ $key->name }}</dd></div>
        <div><dt class="text-gray-500 inline">Environment:</dt> <dd class="inline">{{ $key->environment }}</dd></div>
        <div><dt class="text-gray-500 inline">Expires:</dt> <dd class="inline">{{ $key->expires_at?->toDateString() }}</dd></div>
        <div class="col-span-2"><dt class="text-gray-500 inline">Scopes:</dt> <dd class="inline">{{ implode(', ', (array) $key->scopes) }}</dd></div>
    </dl>
    <p class="text-xs text-gray-500 mt-4">Send it as <code>Authorization: Bearer &lt;key&gt;</code>. Keep it out of source control and client-side code.</p>
    <a href="{{ route('developers.applications.show', $app) }}" class="inline-block mt-4 rounded-lg bg-gray-900 text-white px-4 py-2 text-sm">I've saved it — continue</a>
</div>
@endsection
