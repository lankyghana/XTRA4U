@extends('layouts.vendor')
@section('title', 'Webhooks - XTRA4U')
@section('content')
@php
    $field = 'mt-1 w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-violet focus:border-brand-violet';
    $checkbox = 'mt-0.5 h-4 w-4 text-brand-violet border-gray-300 rounded';
@endphp
<x-developers.shell title="Webhooks" subtitle="Signed HTTPS callbacks when an order or payment changes" tab="applications" :back="['url' => route('developers.applications.show', $app), 'label' => $app->name]">

    <x-developers.section title="Endpoints" :flush="true">
        <div class="divide-y divide-gray-100">
            @forelse($webhooks as $w)
                <div class="px-4 sm:px-5 py-4 text-sm">
                    <div class="flex items-start justify-between gap-3 flex-wrap">
                        <div class="min-w-0">
                            <p class="font-medium text-gray-900 break-all">{{ $w->url }}</p>
                            <div class="flex items-center gap-2 flex-wrap mt-1.5">
                                <x-badge size="sm" :variant="$w->environment === 'sandbox' ? 'pending' : 'completed'">{{ $w->environment === 'sandbox' ? 'SANDBOX' : 'LIVE' }}</x-badge>
                                <x-badge size="sm" :variant="$w->is_active ? 'completed' : 'default'">{{ $w->is_active ? 'Active' : 'Disabled' }}</x-badge>
                                @if($w->consecutive_failures > 0)<x-badge size="sm" variant="failed">{{ $w->consecutive_failures }} failure(s) in a row</x-badge>@endif
                            </div>
                            <p class="text-xs text-gray-500 mt-1.5 break-words">{{ implode(', ', (array) $w->events) }}@if($w->last_delivery_at) &middot; last delivery {{ $w->last_delivery_at->diffForHumans() }}@endif</p>
                        </div>
                        <div class="flex gap-2 flex-wrap">
                            <x-button :href="route('developers.webhooks.deliveries', [$app, $w->id])" variant="outline" size="sm">Deliveries</x-button>
                            <form method="POST" action="{{ route('developers.webhooks.toggle', [$app, $w->id]) }}">@csrf<x-button type="submit" variant="secondary" size="sm">{{ $w->is_active ? 'Disable' : 'Enable' }}</x-button></form>
                            <form method="POST" action="{{ route('developers.webhooks.rotate-secret', [$app, $w->id]) }}" onsubmit="return confirm('Rotate the signing secret? The current secret stops being used immediately.')">@csrf<x-button type="submit" variant="secondary" size="sm">Rotate secret</x-button></form>
                            <form method="POST" action="{{ route('developers.webhooks.destroy', [$app, $w->id]) }}" onsubmit="return confirm('Delete this webhook?')">@csrf @method('DELETE')<x-developers.danger-button>Delete</x-developers.danger-button></form>
                        </div>
                    </div>
                    <details class="mt-3 group">
                        <summary class="inline-block text-sm font-medium text-brand-violet cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-violet rounded">Edit endpoint</summary>
                        <form method="POST" action="{{ route('developers.webhooks.update', [$app, $w->id]) }}" class="mt-3 space-y-3 rounded-lg bg-gray-50 border border-gray-200 p-4">@csrf @method('PUT')
                            <div>
                                <label for="wh-url-{{ $w->id }}" class="block text-sm font-medium text-gray-700">HTTPS URL</label>
                                <input id="wh-url-{{ $w->id }}" name="url" value="{{ $w->url }}" required maxlength="2048" class="{{ $field }}">
                            </div>
                            <div>
                                <label for="wh-desc-{{ $w->id }}" class="block text-sm font-medium text-gray-700">Description <span class="text-gray-400 font-normal">(optional)</span></label>
                                <input id="wh-desc-{{ $w->id }}" name="description" value="{{ $w->description }}" maxlength="191" class="{{ $field }}">
                            </div>
                            <fieldset>
                                <legend class="text-sm font-medium text-gray-700">Events</legend>
                                <div class="grid sm:grid-cols-2 gap-x-6 gap-y-2 mt-2">
                                    @foreach($events as $e)<label class="text-sm text-gray-600 flex items-start gap-2"><input type="checkbox" name="events[]" value="{{ $e }}" class="{{ $checkbox }}" @checked($w->subscribesTo($e))> <code class="text-xs">{{ $e }}</code></label>@endforeach
                                </div>
                            </fieldset>
                            <x-button type="submit" variant="secondary" size="sm">Save changes</x-button>
                        </form>
                    </details>
                </div>
            @empty
                <div class="p-8 text-center">
                    <p class="text-gray-700 font-medium">No webhook endpoints yet</p>
                    <p class="text-sm text-gray-500 mt-1">Add an endpoint below to receive signed events instead of polling.</p>
                </div>
            @endforelse
        </div>
    </x-developers.section>

    <x-developers.section title="Add an endpoint">
        <form method="POST" action="{{ route('developers.webhooks.store', $app) }}" class="space-y-4">@csrf
            <div>
                <label for="new-wh-url" class="block text-sm font-medium text-gray-700">HTTPS URL</label>
                <input id="new-wh-url" name="url" value="{{ old('url') }}" required maxlength="2048" placeholder="https://example.com/xtra4u/webhook" class="{{ $field }}">
            </div>
            <div>
                <label for="new-wh-env" class="block text-sm font-medium text-gray-700">Environment</label>
                <select id="new-wh-env" name="environment" class="{{ $field }}">
                    <option value="sandbox" @selected(old('environment', 'sandbox') === 'sandbox')>Sandbox: receives only simulated test-key events</option>
                    <option value="production" @selected(old('environment') === 'production')>Live: receives only real order events</option>
                </select>
                <p class="mt-1 text-xs text-gray-500">An endpoint belongs to one environment for life. Sandbox events are never sent to live endpoints, and live events are never sent to sandbox endpoints.</p>
            </div>
            <div>
                <label for="new-wh-desc" class="block text-sm font-medium text-gray-700">Description <span class="text-gray-400 font-normal">(optional)</span></label>
                <input id="new-wh-desc" name="description" value="{{ old('description') }}" maxlength="191" class="{{ $field }}">
            </div>
            <fieldset>
                <legend class="text-sm font-medium text-gray-700">Events</legend>
                <div class="grid sm:grid-cols-2 gap-x-6 gap-y-2 mt-2">
                    @foreach($events as $e)<label class="text-sm text-gray-600 flex items-start gap-2"><input type="checkbox" name="events[]" value="{{ $e }}" class="{{ $checkbox }}" @checked(in_array($e, (array) old('events', [])))> <code class="text-xs">{{ $e }}</code></label>@endforeach
                </div>
            </fieldset>
            <p class="text-xs text-gray-500">Destinations must be public HTTPS hosts. Addresses that resolve to private, loopback, link-local or metadata ranges are refused, and re-checked before every delivery. Redirects are not followed.</p>
            <x-button type="submit">Add endpoint</x-button>
        </form>
    </x-developers.section>
</x-developers.shell>
@endsection
