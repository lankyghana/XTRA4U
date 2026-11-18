@extends('layouts.developers')
@section('title', 'Vendor approval required')
@section('content')
<div class="max-w-xl mx-auto bg-white rounded-xl border border-amber-300 p-8 text-center">
    <h1 class="text-xl font-semibold mb-2">Vendor approval required</h1>
    <p class="text-sm text-gray-700 mb-4">The Developer API is available to approved XTRA4U vendors. Your vendor account <b>{{ $vendor->name }}</b> is not currently approved, so API access is switched off: you can't create applications or keys, and any existing keys and webhooks are not active.</p>
    <p class="text-sm text-gray-600 mb-6">Access returns as soon as an administrator approves your vendor account. Keys that were revoked while the account was not approved stay revoked, so you will generate new ones afterwards.</p>
    <a href="{{ route('developers.landing') }}" class="inline-block rounded-lg border border-gray-300 px-4 py-2 text-sm">About the API</a>
</div>
@endsection
