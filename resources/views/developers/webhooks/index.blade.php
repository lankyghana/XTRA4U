@extends('layouts.developers')
@section('title', 'Webhooks')
@section('content')
<p class="text-sm mb-2"><a class="underline" href="{{ route('developers.applications.show', $app) }}">← {{ $app->name }}</a></p>
<h1 class="text-2xl font-semibold mb-6">Webhooks</h1>

<section class="bg-white rounded-xl border border-gray-200 mb-6">
    @forelse($webhooks as $w)
        <div class="px-4 py-4 border-b border-gray-100 text-sm">
            <div class="flex items-center justify-between gap-3 flex-wrap">
                <div>
                    <div class="font-medium break-all">{{ $w->url }}</div>
                    <div class="text-xs text-gray-500">{{ $w->is_active ? 'Active' : 'Disabled' }} · {{ implode(', ', (array) $w->events) }} · failures in a row: {{ $w->consecutive_failures }}@if($w->last_delivery_at) · last delivery {{ $w->last_delivery_at->diffForHumans() }}@endif</div>
                </div>
                <div class="flex gap-2 flex-wrap">
                    <a href="{{ route('developers.webhooks.deliveries', [$app, $w->id]) }}" class="rounded border border-gray-300 px-3 py-1 text-xs">Deliveries</a>
                    <form method="POST" action="{{ route('developers.webhooks.toggle', [$app, $w->id]) }}">@csrf<button class="rounded border border-gray-300 px-3 py-1 text-xs">{{ $w->is_active ? 'Disable' : 'Enable' }}</button></form>
                    <form method="POST" action="{{ route('developers.webhooks.rotate-secret', [$app, $w->id]) }}" onsubmit="return confirm('Rotate the signing secret? The current secret stops being used immediately.')">@csrf<button class="rounded border border-gray-300 px-3 py-1 text-xs">Rotate secret</button></form>
                    <form method="POST" action="{{ route('developers.webhooks.destroy', [$app, $w->id]) }}" onsubmit="return confirm('Delete this webhook?')">@csrf @method('DELETE')<button class="rounded border border-red-300 text-red-700 px-3 py-1 text-xs">Delete</button></form>
                </div>
            </div>
            <details class="mt-3">
                <summary class="text-xs underline cursor-pointer">Edit</summary>
                <form method="POST" action="{{ route('developers.webhooks.update', [$app, $w->id]) }}" class="mt-2 space-y-2">@csrf @method('PUT')
                    <input name="url" value="{{ $w->url }}" required maxlength="2048" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                    <input name="description" value="{{ $w->description }}" maxlength="191" placeholder="Description (optional)" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                    <div class="grid sm:grid-cols-2 gap-1">
                        @foreach($events as $e)<label class="text-xs flex gap-2"><input type="checkbox" name="events[]" value="{{ $e }}" @checked($w->subscribesTo($e))> {{ $e }}</label>@endforeach
                    </div>
                    <button class="rounded border border-gray-300 px-3 py-1 text-xs">Save changes</button>
                </form>
            </details>
        </div>
    @empty
        <p class="px-4 py-6 text-sm text-gray-500">No webhook endpoints yet.</p>
    @endforelse
</section>

<section class="bg-white rounded-xl border border-gray-200 p-4">
    <h2 class="font-semibold mb-3">Add an endpoint</h2>
    <form method="POST" action="{{ route('developers.webhooks.store', $app) }}" class="space-y-3">@csrf
        <label class="block text-sm">HTTPS URL
            <input name="url" value="{{ old('url') }}" required maxlength="2048" placeholder="https://example.com/xtra4u/webhook" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2"></label>
        <label class="block text-sm">Description <input name="description" value="{{ old('description') }}" maxlength="191" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2"></label>
        <fieldset>
            <legend class="text-sm">Events</legend>
            <div class="grid sm:grid-cols-2 gap-1 mt-1">
                @foreach($events as $e)<label class="text-sm flex gap-2"><input type="checkbox" name="events[]" value="{{ $e }}" @checked(in_array($e, (array) old('events', [])))> {{ $e }}</label>@endforeach
            </div>
        </fieldset>
        <p class="text-xs text-gray-500">Destinations must be public HTTPS hosts. Addresses that resolve to private, loopback, link-local or metadata ranges are refused, and re-checked before every delivery. Redirects are not followed.</p>
        <button class="rounded-lg bg-[#00942C] text-white px-4 py-2 text-sm font-medium">Add endpoint</button>
    </form>
</section>
@endsection
