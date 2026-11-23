@extends('layouts.vendor')
@section('title', $app->name.' - XTRA4U')
@section('content')
@php
    $field = 'mt-1 w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-violet focus:border-brand-violet';
    $statusVariant = fn ($s) => match ($s) { 'active' => 'completed', 'expired' => 'pending', default => 'failed' };
@endphp
<x-developers.shell :title="$app->name" subtitle="API keys, webhooks and activity for this application" tab="applications" :back="['url' => route('developers.applications.index'), 'label' => 'Applications']">

    <x-developers.section title="Access">
        <x-slot:actions>
            <x-button :href="route('developers.webhooks.index', $app)" variant="outline" size="sm">Webhooks</x-button>
        </x-slot:actions>
        <dl class="grid sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
            <div class="flex items-center gap-2"><dt class="text-gray-500">Status:</dt><dd><x-badge size="sm" :variant="$statusVariant($app->status)">{{ $app->status }}</x-badge></dd></div>
            <div><dt class="text-gray-500 inline">Store:</dt> <dd class="inline font-medium text-gray-900">{{ $app->vendor?->name }} <span class="text-gray-400 font-normal">(your products only)</span></dd></div>
            <div><dt class="text-gray-500 inline">Rate limit:</dt> <dd class="inline font-medium text-gray-900">{{ $app->effectiveRateLimitPerMinute() }} requests/minute</dd></div>
            <div><dt class="text-gray-500 inline">Webhook endpoints:</dt> <dd class="inline font-medium text-gray-900">{{ $webhookCount }}</dd></div>
        </dl>
        <p class="mt-4 text-xs text-gray-500">Test (<code class="bg-gray-100 rounded px-1">xt_test_</code>) keys use the sandbox: simulated orders and payments, nothing real is charged, delivered or credited. Live (<code class="bg-gray-100 rounded px-1">xt_live_</code>) keys create real orders.
            Test keys and live keys are both available to you. If your vendor account ever loses approval, every key here is revoked and every webhook disabled.</p>
    </x-developers.section>

    <x-developers.section title="API keys" :flush="true">
        <div class="divide-y divide-gray-100">
            @forelse($keys as $k)
                <div class="px-4 sm:px-5 py-4 flex items-start justify-between gap-3 flex-wrap text-sm">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <b class="text-gray-900">{{ $k->name }}</b>
                            <x-badge size="sm" :variant="$k->environment === 'production' ? 'completed' : 'pending'">{{ $k->environment === 'production' ? 'LIVE' : 'TEST (sandbox)' }}</x-badge>
                            <x-badge size="sm" :variant="$statusVariant($k->status())">{{ $k->status() }}</x-badge>
                        </div>
                        <code class="inline-block mt-1.5 text-xs bg-gray-100 rounded px-1.5 py-0.5 break-all">xt_{{ $k->environment === 'production' ? 'live' : 'test' }}_{{ $k->prefix }}_••••</code>
                        <p class="text-xs text-gray-500 mt-1.5">
                            created {{ $k->created_at->toDateString() }} &middot;
                            expires {{ $k->expires_at?->toDateString() ?? 'never' }} &middot;
                            last used {{ $k->last_used_at?->diffForHumans() ?? 'never' }}
                        </p>
                        <p class="text-xs text-gray-500 mt-0.5 break-words">Scopes: {{ implode(', ', (array) $k->scopes) }}</p>
                    </div>
                    @if(! $k->isRevoked())
                        <div class="flex gap-2">
                            <form method="POST" action="{{ route('developers.keys.rotate', [$app, $k->id]) }}" onsubmit="return confirm('Rotate this key? The old key stops working immediately.')">@csrf
                                <x-button type="submit" variant="secondary" size="sm">Rotate</x-button></form>
                            <form method="POST" action="{{ route('developers.keys.revoke', [$app, $k->id]) }}" onsubmit="return confirm('Revoke this key?')">@csrf
                                <x-developers.danger-button>Revoke</x-developers.danger-button></form>
                        </div>
                    @endif
                </div>
            @empty
                <div class="p-8 text-center">
                    <p class="text-gray-700 font-medium">No API keys yet</p>
                    <p class="text-sm text-gray-500 mt-1">Generate a test key below to try the sandbox.</p>
                </div>
            @endforelse
        </div>

        @if($app->status === 'active')
        <form method="POST" action="{{ route('developers.keys.store', $app) }}" class="p-4 sm:p-5 space-y-4 bg-gray-50 border-t border-gray-200">@csrf
            <h3 class="text-sm font-semibold text-gray-900">Create a key</h3>
            <div class="grid sm:grid-cols-3 gap-4">
                <div>
                    <label for="key-name" class="block text-sm font-medium text-gray-700">Name</label>
                    <input id="key-name" name="name" required maxlength="100" class="{{ $field }}">
                </div>
                <div>
                    <label for="key-env" class="block text-sm font-medium text-gray-700">Environment</label>
                    <select id="key-env" name="environment" class="{{ $field }}">
                        <option value="sandbox">Test (sandbox)</option>
                        <option value="production">Live (production)</option>
                    </select>
                </div>
                <div>
                    <label for="key-expiry" class="block text-sm font-medium text-gray-700">Expires in</label>
                    <select id="key-expiry" name="expires_in_days" class="{{ $field }}">
                        @foreach([30 => '30 days', 90 => '90 days', 180 => '180 days', 365 => '1 year'] as $days => $label)<option value="{{ $days }}" @selected($days === 90)>{{ $label }}</option>@endforeach
                    </select>
                </div>
            </div>
            <fieldset>
                <legend class="text-sm font-medium text-gray-700">Scopes</legend>
                <div class="grid sm:grid-cols-2 gap-x-6 gap-y-2 mt-2">
                    @foreach($scopes as $scope => $desc)
                        <label class="text-xs text-gray-600 flex items-start gap-2"><input type="checkbox" name="scopes[]" value="{{ $scope }}" class="mt-0.5 h-4 w-4 text-brand-violet border-gray-300 rounded" @checked(in_array($scope, ['services:read', 'products:read']))><span><code class="bg-white border border-gray-200 rounded px-1">{{ $scope }}</code> &mdash; {{ $desc }}@if(in_array($scope, (array) config('developer_api.sandbox_only_scopes', []), true)) <em>(sandbox keys only)</em>@endif</span></label>
                    @endforeach
                </div>
            </fieldset>
            <div class="flex items-center gap-3 flex-wrap">
                <x-button type="submit">Generate key</x-button>
                <p class="text-xs text-gray-500">The full key is shown once, right after you generate it. We store only a hash.</p>
            </div>
        </form>
        @endif
    </x-developers.section>

    @include('developers.partials.sandbox-activity', ['sandbox' => $sandbox])

    <x-developers.section title="Recent requests" :flush="true">
        <x-slot:actions>
            <x-button :href="route('developers.applications.requests', $app)" variant="outline" size="sm">All request logs</x-button>
        </x-slot:actions>
        @include('developers.partials.request-rows', ['rows' => $recentRequests, 'time' => 'ago', 'empty' => 'No requests yet'])
    </x-developers.section>

    @if($app->status === 'active')
    <x-developers.section title="Details">
        <form method="POST" action="{{ route('developers.applications.update', $app) }}" class="space-y-4">@csrf @method('PUT')
            <div>
                <label for="detail-name" class="block text-sm font-medium text-gray-700">Name</label>
                <input id="detail-name" name="name" value="{{ old('name', $app->name) }}" required maxlength="120" class="{{ $field }}">
            </div>
            <div>
                <label for="detail-description" class="block text-sm font-medium text-gray-700">Description</label>
                <textarea id="detail-description" name="description" rows="2" maxlength="1000" class="{{ $field }}">{{ old('description', $app->description) }}</textarea>
            </div>
            <x-button type="submit" variant="secondary">Save</x-button>
        </form>

        <div class="mt-6 pt-5 border-t border-gray-200 flex items-center justify-between gap-3 flex-wrap">
            <div>
                <p class="text-sm font-medium text-gray-900">Revoke this application</p>
                <p class="text-xs text-gray-500">All its keys and webhooks stop working. This cannot be undone.</p>
            </div>
            <form method="POST" action="{{ route('developers.applications.destroy', $app) }}" onsubmit="return confirm('Revoke this application permanently? All its keys and webhooks stop working.')">@csrf @method('DELETE')
                <x-button type="submit" variant="danger" size="sm">Revoke application</x-button>
            </form>
        </div>
    </x-developers.section>
    @endif
</x-developers.shell>
@endsection
