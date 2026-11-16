@extends('layouts.developers')
@section('title', $app->name)
@section('content')
<div class="flex items-center justify-between mb-6 flex-wrap gap-2">
    <h1 class="text-2xl font-semibold">{{ $app->name }}</h1>
    <a href="{{ route('developers.webhooks.index', $app) }}" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm">Webhooks</a>
</div>

<section class="bg-white rounded-xl border border-gray-200 p-4 mb-6">
    <h2 class="font-semibold mb-3">Access</h2>
    <dl class="grid sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
        <div><dt class="text-gray-500 inline">Status:</dt> <dd class="inline font-medium">{{ $app->status }}</dd></div>
        <div><dt class="text-gray-500 inline">Type:</dt> <dd class="inline font-medium">{{ $app->vendor_id ? 'Vendor-bound ('.($app->vendor?->name ?? 'vendor').')' : 'Marketplace' }}</dd></div>
        <div><dt class="text-gray-500 inline">Environment:</dt> <dd class="inline font-medium">{{ $app->environment }}</dd></div>
        <div><dt class="text-gray-500 inline">Production access:</dt> <dd class="inline font-medium" data-production-status>{{ $app->production_status }}</dd></div>
        @if($app->vendor_id)
            <div><dt class="text-gray-500 inline">Vendor approval:</dt> <dd class="inline font-medium" data-vendor-status>{{ $app->vendor_link_status }}</dd></div>
        @endif
        <div><dt class="text-gray-500 inline">Rate limit:</dt> <dd class="inline font-medium">{{ $app->effectiveRateLimitPerMinute() }} requests/minute</dd></div>
    </dl>
    @if($app->production_status === 'rejected' && $app->production_review_note)
        <p class="mt-3 text-sm text-red-700">Reviewer note: {{ $app->production_review_note }}</p>
    @endif
    @if($app->status === 'active' && ! in_array($app->production_status, ['requested', 'approved'], true))
        <form method="POST" action="{{ route('developers.applications.production-request', $app) }}" class="mt-4">@csrf
            <button class="rounded-lg bg-gray-900 text-white px-4 py-2 text-sm" @disabled($app->vendor_id && $app->vendor_link_status !== 'approved')>Request production access</button>
            @if($app->vendor_id && $app->vendor_link_status !== 'approved')
                <span class="text-xs text-gray-500 ml-2">Available once the vendor approves this application.</span>
            @endif
        </form>
    @elseif($app->production_status === 'requested')
        <p class="mt-3 text-sm text-amber-700">Production access is awaiting administrator review. Production keys can't be created until it is approved.</p>
    @endif
</section>

<section class="bg-white rounded-xl border border-gray-200 mb-6">
    <h2 class="font-semibold px-4 py-3 border-b border-gray-200">API keys</h2>
    @forelse($keys as $k)
        <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between gap-3 flex-wrap text-sm">
            <div>
                <div><b>{{ $k->name }}</b> <code class="text-xs bg-gray-100 rounded px-1">xt_{{ $k->environment === 'production' ? 'live' : 'test' }}_{{ $k->prefix }}_••••</code></div>
                <div class="text-xs text-gray-500">
                    {{ $k->environment }} · {{ $k->status() }} · created {{ $k->created_at->toDateString() }} ·
                    expires {{ $k->expires_at?->toDateString() ?? 'never' }} ·
                    last used {{ $k->last_used_at?->diffForHumans() ?? 'never' }}
                </div>
                <div class="text-xs text-gray-500">Scopes: {{ implode(', ', (array) $k->scopes) }}</div>
            </div>
            @if(! $k->isRevoked())
                <div class="flex gap-2">
                    <form method="POST" action="{{ route('developers.keys.rotate', [$app, $k->id]) }}" onsubmit="return confirm('Rotate this key? The old key stops working immediately.')">@csrf
                        <button class="rounded border border-gray-300 px-3 py-1 text-xs">Rotate</button></form>
                    <form method="POST" action="{{ route('developers.keys.revoke', [$app, $k->id]) }}" onsubmit="return confirm('Revoke this key?')">@csrf
                        <button class="rounded border border-red-300 text-red-700 px-3 py-1 text-xs">Revoke</button></form>
                </div>
            @endif
        </div>
    @empty
        <p class="px-4 py-6 text-sm text-gray-500">No keys yet.</p>
    @endforelse

    @if($app->status === 'active')
    <form method="POST" action="{{ route('developers.keys.store', $app) }}" class="p-4 space-y-3 bg-gray-50 rounded-b-xl">@csrf
        <h3 class="text-sm font-medium">Create a key</h3>
        <div class="grid sm:grid-cols-3 gap-3">
            <label class="text-sm">Name <input name="name" required maxlength="100" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2"></label>
            <label class="text-sm">Environment
                <select name="environment" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2">
                    <option value="sandbox">Test (sandbox)</option>
                    <option value="production" @disabled(! $app->hasProductionAccess())>Live (production){{ $app->hasProductionAccess() ? '' : ' — not approved' }}</option>
                </select></label>
            <label class="text-sm">Expires in
                <select name="expires_in_days" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2">
                    @foreach([30 => '30 days', 90 => '90 days', 180 => '180 days', 365 => '1 year'] as $days => $label)<option value="{{ $days }}" @selected($days === 90)>{{ $label }}</option>@endforeach
                </select></label>
        </div>
        <fieldset>
            <legend class="text-sm">Scopes</legend>
            <div class="grid sm:grid-cols-2 gap-1 mt-1">
                @foreach($scopes as $scope => $desc)
                    <label class="text-xs flex items-start gap-2"><input type="checkbox" name="scopes[]" value="{{ $scope }}" class="mt-0.5" @checked(in_array($scope, ['services:read', 'products:read']))><span><code>{{ $scope }}</code> — {{ $desc }}</span></label>
                @endforeach
            </div>
        </fieldset>
        <button class="rounded-lg bg-[#00942C] text-white px-4 py-2 text-sm font-medium">Generate key</button>
        <p class="text-xs text-gray-500">The full key is shown once, right after you generate it. We store only a hash.</p>
    </form>
    @endif
</section>

<section class="bg-white rounded-xl border border-gray-200 mb-6">
    <h2 class="font-semibold px-4 py-3 border-b border-gray-200">Recent requests</h2>
    @forelse($recentRequests as $r)
        <div class="px-4 py-2 text-xs border-b border-gray-100 flex justify-between gap-2">
            <span><b>{{ $r->method }}</b> {{ $r->path }} <span class="text-gray-400">{{ $r->request_id }}</span></span>
            <span>{{ $r->status_code }}{{ $r->error_code ? ' '.$r->error_code : '' }} · {{ $r->duration_ms }}ms · {{ $r->created_at->diffForHumans() }}</span>
        </div>
    @empty
        <p class="px-4 py-4 text-sm text-gray-500">No requests yet.</p>
    @endforelse
</section>

@if($app->status === 'active')
<section class="bg-white rounded-xl border border-gray-200 p-4 mb-6">
    <h2 class="font-semibold mb-3">Details</h2>
    <form method="POST" action="{{ route('developers.applications.update', $app) }}" class="space-y-3">@csrf @method('PUT')
        <label class="block text-sm">Name <input name="name" value="{{ old('name', $app->name) }}" required maxlength="120" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2"></label>
        <label class="block text-sm">Description <textarea name="description" rows="2" maxlength="1000" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2">{{ old('description', $app->description) }}</textarea></label>
        <button class="rounded-lg border border-gray-300 px-4 py-2 text-sm">Save</button>
    </form>
    <form method="POST" action="{{ route('developers.applications.destroy', $app) }}" class="mt-4" onsubmit="return confirm('Revoke this application permanently? All its keys and webhooks stop working.')">@csrf @method('DELETE')
        <button class="text-sm text-red-700 underline">Revoke application</button>
    </form>
</section>
@endif
@endsection
