@extends('layouts.developers')
@section('title', 'Dashboard')
@section('content')
<h1 class="text-2xl font-semibold mb-6">Dashboard</h1>

<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
    <div class="bg-white rounded-xl border border-gray-200 p-4"><div class="text-xs text-gray-500">Applications</div><div class="text-2xl font-semibold" data-stat="applications">{{ $d['application_count'] }}</div></div>
    <div class="bg-white rounded-xl border border-gray-200 p-4"><div class="text-xs text-gray-500">Active API keys</div><div class="text-2xl font-semibold" data-stat="active-keys">{{ $d['active_keys'] }}</div></div>
    <div class="bg-white rounded-xl border border-gray-200 p-4"><div class="text-xs text-gray-500">Requests (24h)</div><div class="text-2xl font-semibold" data-stat="requests-24h">{{ number_format($d['usage']['24h']['total']) }}</div></div>
    <div class="bg-white rounded-xl border border-gray-200 p-4"><div class="text-xs text-gray-500">Failed (24h)</div><div class="text-2xl font-semibold" data-stat="failed-24h">{{ number_format($d['usage']['24h']['failed']) }}</div></div>
</div>

<section class="bg-white rounded-xl border border-gray-200 mb-8">
    <div class="px-4 py-3 border-b border-gray-200 flex items-center justify-between">
        <h2 class="font-semibold">Applications</h2>
        <a href="{{ route('developers.applications.create') }}" class="text-sm underline">New application</a>
    </div>
    @forelse($d['applications'] as $a)
        <a href="{{ route('developers.applications.show', $a) }}" class="flex items-center justify-between px-4 py-3 border-b border-gray-100 hover:bg-gray-50">
            <span class="font-medium">{{ $a->name }}</span>
            <span class="text-xs flex gap-2">
                <span class="rounded-full bg-gray-100 px-2 py-0.5">{{ $a->environment }}</span>
                <span class="rounded-full bg-gray-100 px-2 py-0.5">production: {{ $a->production_status }}</span>
                @if($a->vendor_id)<span class="rounded-full bg-gray-100 px-2 py-0.5">vendor: {{ $a->vendor_link_status }}</span>@endif
                <span class="rounded-full {{ $a->status === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }} px-2 py-0.5">{{ $a->status }}</span>
            </span>
        </a>
    @empty
        <p class="px-4 py-6 text-sm text-gray-500">You have no applications yet.</p>
    @endforelse
</section>

<div class="grid md:grid-cols-3 gap-4 mb-8">
    @foreach(['24h' => 'Last 24 hours', '7d' => 'Last 7 days', '30d' => 'Last 30 days'] as $k => $label)
        <div class="bg-white rounded-xl border border-gray-200 p-4">
            <div class="text-xs text-gray-500 mb-1">API usage — {{ $label }}</div>
            <div class="text-sm">Total <b>{{ number_format($d['usage'][$k]['total']) }}</b> · OK <b>{{ number_format($d['usage'][$k]['successful']) }}</b> · Failed <b>{{ number_format($d['usage'][$k]['failed']) }}</b></div>
        </div>
    @endforeach
</div>

<section class="bg-white rounded-xl border border-gray-200 mb-8">
    <h2 class="font-semibold px-4 py-3 border-b border-gray-200">Rate limits</h2>
    @forelse($d['rate_limits'] as $r)
        <div class="px-4 py-2 text-sm border-b border-gray-100 flex justify-between"><span>{{ $r['application']->name }}</span><span>{{ $r['per_minute'] }} requests/minute <span class="text-gray-400">({{ $r['source'] }})</span></span></div>
    @empty
        <p class="px-4 py-4 text-sm text-gray-500">No applications.</p>
    @endforelse
</section>

<div class="grid md:grid-cols-2 gap-4 mb-8">
    <section class="bg-white rounded-xl border border-gray-200">
        <h2 class="font-semibold px-4 py-3 border-b border-gray-200">Recent requests</h2>
        @forelse($d['recent_requests'] as $r)
            <div class="px-4 py-2 text-xs border-b border-gray-100 flex justify-between gap-2">
                <span><b>{{ $r->method }}</b> {{ $r->path }}</span><span>{{ $r->status_code }} · {{ $r->duration_ms }}ms · {{ $r->created_at->diffForHumans() }}</span>
            </div>
        @empty
            <p class="px-4 py-4 text-sm text-gray-500">No API requests yet.</p>
        @endforelse
    </section>
    <section class="bg-white rounded-xl border border-gray-200">
        <h2 class="font-semibold px-4 py-3 border-b border-gray-200">Recent errors</h2>
        @forelse($d['recent_errors'] as $r)
            <div class="px-4 py-2 text-xs border-b border-gray-100 flex justify-between gap-2">
                <span><b>{{ $r->method }}</b> {{ $r->path }}</span><span>{{ $r->status_code }} {{ $r->error_code }} · {{ $r->created_at->diffForHumans() }}</span>
            </div>
        @empty
            <p class="px-4 py-4 text-sm text-gray-500">No errors.</p>
        @endforelse
    </section>
</div>

<section class="bg-white rounded-xl border border-gray-200">
    <h2 class="font-semibold px-4 py-3 border-b border-gray-200">Webhooks</h2>
    <div class="px-4 py-3 text-sm">
        {{ $d['webhooks']['active_endpoints'] }} active of {{ $d['webhooks']['endpoints'] }} endpoints ·
        last 7 days: <b>{{ $d['webhooks']['deliveries_7d']['succeeded'] }}</b> delivered,
        <b>{{ $d['webhooks']['deliveries_7d']['pending'] }}</b> pending,
        <b>{{ $d['webhooks']['deliveries_7d']['failed'] }}</b> failed
    </div>
    @foreach($d['webhooks']['recent'] as $del)
        <div class="px-4 py-2 text-xs border-t border-gray-100 flex justify-between">
            <span>{{ $del->event?->type }} → {{ $del->webhook?->displayHost() }}</span>
            <span>{{ $del->status }}@if($del->last_status_code) ({{ $del->last_status_code }})@endif</span>
        </div>
    @endforeach
</section>
@endsection
