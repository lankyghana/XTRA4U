@extends('layouts.vendor')
@section('title', 'Webhook deliveries - XTRA4U')
@section('content')
@php
    $deliveryVariant = fn ($s) => match ($s) { 'succeeded' => 'completed', 'pending' => 'pending', 'failed' => 'failed', default => 'default' };
@endphp
<x-developers.shell title="Deliveries" subtitle="Every attempt to deliver an event to this endpoint" tab="applications" :back="['url' => route('developers.webhooks.index', $app), 'label' => 'Webhooks']">
    <div class="flex items-center gap-2 flex-wrap text-sm text-gray-600">
        <x-badge size="sm" :variant="$webhook->environment === 'sandbox' ? 'pending' : 'completed'">{{ $webhook->environment === 'sandbox' ? 'SANDBOX' : 'LIVE' }}</x-badge>
        <span class="break-all">{{ $webhook->url }}</span>
    </div>

    <x-developers.section :flush="true">
        <div class="divide-y divide-gray-100">
            @forelse($deliveries as $d)
                <div class="px-4 sm:px-5 py-3 text-sm">
                    <div class="flex items-start justify-between gap-3 flex-wrap">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <b class="text-gray-900">{{ $d->event?->type }}</b>
                                <x-badge size="sm" :variant="$deliveryVariant($d->status)">{{ $d->status }}</x-badge>
                            </div>
                            <p class="text-xs text-gray-500 mt-1 break-words">
                                <code>{{ $d->event?->event_id }}</code>
                                @if($d->last_status_code) &middot; HTTP {{ $d->last_status_code }}@endif
                                @if($d->last_error) &middot; {{ $d->last_error }}@endif
                                &middot; attempts {{ $d->total_attempts }}
                                @if($d->next_attempt_at && $d->status === 'pending') &middot; next {{ $d->next_attempt_at->diffForHumans() }}@endif
                            </p>
                        </div>
                        @if($d->status === 'failed' && $webhook->is_active)
                            <form method="POST" action="{{ route('developers.webhooks.retry', [$app, $webhook->id, $d->id]) }}">@csrf<x-button type="submit" variant="secondary" size="sm">Retry</x-button></form>
                        @endif
                    </div>
                    @if($d->attemptLog->isNotEmpty())
                        <ul class="mt-2 text-xs text-gray-500 space-y-0.5">
                            @foreach($d->attemptLog as $a)
                                <li>#{{ $a->attempt_no }} {{ $a->created_at->toDateTimeString() }} &mdash; {{ $a->outcome }}@if($a->status_code) ({{ $a->status_code }})@endif &middot; {{ $a->duration_ms }}ms</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @empty
                <p class="px-4 sm:px-5 py-8 text-center text-sm text-gray-500">No deliveries yet</p>
            @endforelse
        </div>
    </x-developers.section>
    <div>{{ $deliveries->links() }}</div>
</x-developers.shell>
@endsection
