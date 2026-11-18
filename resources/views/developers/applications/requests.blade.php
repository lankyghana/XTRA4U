@extends('layouts.developers')
@section('title', 'Request logs')
@section('content')
<p class="text-sm mb-2"><a class="underline" href="{{ route('developers.applications.show', $app) }}">← {{ $app->name }}</a></p>
<h1 class="text-2xl font-semibold mb-1">Request logs</h1>
<p class="text-sm text-gray-500 mb-6">Every API request made with this application's keys. Credentials and request bodies are never logged.</p>
<section class="bg-white rounded-xl border border-gray-200">
    @forelse($requests as $r)
        <div class="px-4 py-2 text-xs border-b border-gray-100 flex justify-between gap-2 flex-wrap">
            <span><b>{{ $r->method }}</b> {{ $r->path }} @if($r->environment === 'sandbox')<span class="rounded-full bg-amber-100 text-amber-800 px-1.5">test</span>@endif <span class="text-gray-400">{{ $r->request_id }}</span></span>
            <span>{{ $r->status_code }}{{ $r->error_code ? ' '.$r->error_code : '' }} · {{ $r->duration_ms }}ms · {{ $r->created_at->toDateTimeString() }}</span>
        </div>
    @empty
        <p class="px-4 py-6 text-sm text-gray-500">No requests yet.</p>
    @endforelse
</section>
<div class="mt-4">{{ $requests->links() }}</div>
@endsection
