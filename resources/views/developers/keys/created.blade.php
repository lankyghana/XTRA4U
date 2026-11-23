@extends('layouts.vendor')
@section('title', 'API key '.$verb.' - XTRA4U')
@section('content')
<x-developers.shell :title="'API key '.$verb" subtitle="Copy it now: it is shown only once" tab="applications">
    <x-developers.section tone="secret" class="max-w-3xl">
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 mb-4" role="alert">
            Copy this key now. <b>It will not be shown again</b> &mdash; XTRA4U stores only a one-way hash, so a lost key can't be recovered, only replaced.
        </div>

        <x-developers.secret id="new-api-key" label="API key" :value="$plaintext" />

        <dl class="mt-5 text-sm grid sm:grid-cols-2 gap-x-6 gap-y-2">
            <div><dt class="text-gray-500 inline">Name:</dt> <dd class="inline text-gray-900">{{ $key->name }}</dd></div>
            <div class="flex items-center gap-2"><dt class="text-gray-500">Environment:</dt> <dd><x-badge size="sm" :variant="$key->environment === 'production' ? 'completed' : 'pending'">{{ $key->environment }}</x-badge></dd></div>
            <div><dt class="text-gray-500 inline">Expires:</dt> <dd class="inline text-gray-900">{{ $key->expires_at?->toDateString() }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-gray-500 inline">Scopes:</dt> <dd class="inline text-gray-900 break-words">{{ implode(', ', (array) $key->scopes) }}</dd></div>
        </dl>

        <p class="text-xs text-gray-500 mt-4">Send it as <code class="bg-gray-100 rounded px-1">Authorization: Bearer &lt;key&gt;</code>. Keep it out of source control and client-side code.</p>
        <x-button :href="route('developers.applications.show', $app)" class="mt-4">I've saved it &mdash; continue</x-button>
    </x-developers.section>
</x-developers.shell>
@endsection
