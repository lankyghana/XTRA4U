@extends('layouts.vendor')
@section('title', 'Developer API - XTRA4U')
@section('content')
<x-developers.shell title="Developer API" subtitle="Applications, API keys, webhooks and usage for your store" tab="dashboard">

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <x-card variant="metric" padding="sm" rounded="xl"><div class="text-xs text-gray-500 uppercase tracking-wide">Applications</div><div class="text-2xl font-semibold text-gray-900 mt-1" data-stat="applications">{{ $d['application_count'] }}</div></x-card>
        <x-card variant="metric" padding="sm" rounded="xl"><div class="text-xs text-gray-500 uppercase tracking-wide">Active API keys</div><div class="text-2xl font-semibold text-gray-900 mt-1" data-stat="active-keys">{{ $d['active_keys'] }}</div><div class="text-xs text-gray-500 mt-1">{{ $d['active_keys_by_environment']['production'] }} live &middot; {{ $d['active_keys_by_environment']['sandbox'] }} test</div></x-card>
        <x-card variant="metric" padding="sm" rounded="xl"><div class="text-xs text-gray-500 uppercase tracking-wide">Requests (24h)</div><div class="text-2xl font-semibold text-gray-900 mt-1" data-stat="requests-24h">{{ number_format($d['usage']['24h']['total']) }}</div><div class="text-xs text-gray-500 mt-1">{{ number_format($d['usage_by_environment']['production']) }} live &middot; {{ number_format($d['usage_by_environment']['sandbox']) }} test</div></x-card>
        <x-card variant="metric" padding="sm" rounded="xl"><div class="text-xs text-gray-500 uppercase tracking-wide">Failed (24h)</div><div class="text-2xl font-semibold text-gray-900 mt-1" data-stat="failed-24h">{{ number_format($d['usage']['24h']['failed']) }}</div></x-card>
    </div>

    @include('developers.partials.sandbox-activity', ['sandbox' => $d['sandbox']])

    <x-developers.section title="Applications" :flush="true">
        <x-slot:actions>
            <x-button :href="route('developers.applications.create')" size="sm">New application</x-button>
        </x-slot:actions>
        <div class="divide-y divide-gray-100">
            @forelse($d['applications'] as $a)
                <a href="{{ route('developers.applications.show', $a) }}" class="flex items-center justify-between gap-3 px-4 sm:px-5 py-3 hover:bg-gray-50 dev-row">
                    <span class="font-medium text-gray-900 truncate">{{ $a->name }}</span>
                    <x-badge size="sm" :variant="$a->status === 'active' ? 'completed' : 'failed'">{{ $a->status }}</x-badge>
                </a>
            @empty
                <div class="p-8 text-center">
                    <p class="text-gray-700 font-medium">You have no applications yet</p>
                    <p class="text-sm text-gray-500 mt-1">Create one to get API keys.</p>
                </div>
            @endforelse
        </div>
    </x-developers.section>

    <div class="grid md:grid-cols-3 gap-4">
        @foreach(['24h' => 'Last 24 hours', '7d' => 'Last 7 days', '30d' => 'Last 30 days'] as $k => $label)
            <x-card padding="sm" rounded="xl">
                <div class="text-xs text-gray-500 uppercase tracking-wide mb-2">API usage &mdash; {{ $label }}</div>
                <div class="text-sm text-gray-600">Total <b class="text-gray-900">{{ number_format($d['usage'][$k]['total']) }}</b> &middot; OK <b class="text-gray-900">{{ number_format($d['usage'][$k]['successful']) }}</b> &middot; Failed <b class="text-gray-900">{{ number_format($d['usage'][$k]['failed']) }}</b></div>
            </x-card>
        @endforeach
    </div>

    <x-developers.section title="Rate limits" :flush="true">
        <div class="divide-y divide-gray-100">
            @forelse($d['rate_limits'] as $r)
                <div class="px-4 sm:px-5 py-2.5 text-sm flex justify-between gap-3 flex-wrap"><span class="text-gray-900">{{ $r['application']->name }}</span><span class="text-gray-600">{{ $r['per_minute'] }} requests/minute <span class="text-gray-400">({{ $r['source'] }})</span></span></div>
            @empty
                <p class="px-4 sm:px-5 py-6 text-center text-sm text-gray-500">No applications</p>
            @endforelse
        </div>
    </x-developers.section>

    <div class="space-y-6">
        <x-developers.section title="Recent requests" :flush="true">
            @include('developers.partials.request-rows', ['rows' => $d['recent_requests'], 'time' => 'ago', 'empty' => 'No API requests yet'])
        </x-developers.section>
        <x-developers.section title="Recent errors" :flush="true">
            @include('developers.partials.request-rows', ['rows' => $d['recent_errors'], 'time' => 'ago', 'empty' => 'No errors'])
        </x-developers.section>
    </div>

    <x-developers.section title="Webhooks" :flush="true">
        <p class="px-4 sm:px-5 pt-3 text-xs text-gray-500">Live endpoints. Sandbox deliveries are shown under Sandbox activity.</p>
        <div class="px-4 sm:px-5 py-3 text-sm text-gray-600">
            {{ $d['webhooks']['active_endpoints'] }} active of {{ $d['webhooks']['endpoints'] }} endpoints &middot;
            last 7 days: <b class="text-gray-900">{{ $d['webhooks']['deliveries_7d']['succeeded'] }}</b> delivered,
            <b class="text-gray-900">{{ $d['webhooks']['deliveries_7d']['pending'] }}</b> pending,
            <b class="text-gray-900">{{ $d['webhooks']['deliveries_7d']['failed'] }}</b> failed
        </div>
        @foreach($d['webhooks']['recent'] as $del)
            <div class="px-4 sm:px-5 py-2 text-xs border-t border-gray-100 flex justify-between gap-2">
                <span class="break-all">{{ $del->event?->type }} &rarr; {{ $del->webhook?->displayHost() }}</span>
                <span class="text-gray-600">{{ $del->status }}@if($del->last_status_code) ({{ $del->last_status_code }})@endif</span>
            </div>
        @endforeach
    </x-developers.section>
</x-developers.shell>
@endsection
