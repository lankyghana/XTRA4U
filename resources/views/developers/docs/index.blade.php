@extends('layouts.vendor')
@section('title', 'API documentation - XTRA4U')
@section('content')
<x-developers.shell title="API documentation" subtitle="XTRA4U Developer API v1: authentication, every endpoint, the sandbox, webhooks and errors" tab="docs">
    @php $quick = $pages['quickstart'] ?? null; $rest = collect($pages)->except('quickstart'); @endphp

    @if($quick)
        <a href="{{ route('developers.docs.show', 'quickstart') }}" class="block rounded-xl border border-violet-200 bg-brand-violet-soft p-5 hover:border-brand-violet focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-violet">
            <x-badge size="sm" variant="processing">Start here</x-badge>
            <p class="mt-2 text-lg font-semibold text-gray-900">{{ $quick['title'] }}</p>
            <p class="text-sm text-gray-600 mt-1">{{ $quick['summary'] }}</p>
        </a>
    @endif

    <div class="grid sm:grid-cols-2 gap-4">
        @foreach($rest as $slug => $page)
            <a href="{{ route('developers.docs.show', $slug) }}" class="block bg-white rounded-xl border border-gray-200 p-4 hover:border-brand-violet hover:shadow-md transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-violet">
                <p class="font-semibold text-gray-900">{{ $page['title'] }}</p>
                <p class="text-sm text-gray-600 mt-1">{{ $page['summary'] }}</p>
            </a>
        @endforeach
    </div>

    <p class="text-xs text-gray-500">The documentation describes only what the API does today. API keys, applications and webhook endpoints are managed in the portal; they are not part of the API.</p>
</x-developers.shell>
@endsection
