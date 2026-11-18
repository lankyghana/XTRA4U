@extends('layouts.developers')
@section('title', 'Webhook deliveries')
@section('content')
<p class="text-sm mb-2"><a class="underline" href="{{ route('developers.webhooks.index', $app) }}">← Webhooks</a></p>
<h1 class="text-2xl font-semibold mb-1">Deliveries</h1>
<p class="text-sm text-gray-500 mb-6 break-all"><span class="rounded-full px-2 py-0.5 text-xs {{ $webhook->environment === 'sandbox' ? 'bg-amber-100 text-amber-800' : 'bg-green-100 text-green-800' }}">{{ $webhook->environment === 'sandbox' ? 'SANDBOX' : 'LIVE' }}</span> {{ $webhook->url }}</p>

<section class="bg-white rounded-xl border border-gray-200">
    @forelse($deliveries as $d)
        <div class="px-4 py-3 border-b border-gray-100 text-sm">
            <div class="flex items-center justify-between gap-3 flex-wrap">
                <div>
                    <b>{{ $d->event?->type }}</b> <code class="text-xs text-gray-500">{{ $d->event?->event_id }}</code>
                    <div class="text-xs text-gray-500">
                        {{ $d->status }}
                        @if($d->last_status_code)
                            · HTTP {{ $d->last_status_code }}
                        @endif
                        @if($d->last_error)
                            · {{ $d->last_error }}
                        @endif
                        · attempts {{ $d->total_attempts }}
                        @if($d->next_attempt_at && $d->status === 'pending')
                            · next {{ $d->next_attempt_at->diffForHumans() }}
                        @endif
                    </div>
                </div>
                @if($d->status === 'failed' && $webhook->is_active)
                    <form method="POST" action="{{ route('developers.webhooks.retry', [$app, $webhook->id, $d->id]) }}">@csrf<button class="rounded border border-gray-300 px-3 py-1 text-xs">Retry</button></form>
                @endif
            </div>
            @if($d->attemptLog->isNotEmpty())
                <ul class="mt-2 text-xs text-gray-500 space-y-0.5">
                    @foreach($d->attemptLog as $a)
                        <li>#{{ $a->attempt_no }} {{ $a->created_at->toDateTimeString() }} — {{ $a->outcome }}@if($a->status_code) ({{ $a->status_code }})@endif · {{ $a->duration_ms }}ms</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @empty
        <p class="px-4 py-6 text-sm text-gray-500">No deliveries yet.</p>
    @endforelse
</section>
<div class="mt-4">{{ $deliveries->links() }}</div>
@endsection
