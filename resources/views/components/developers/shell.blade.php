{{--
    Developer Portal page shell. The portal is a section of the vendor dashboard: same sidebar, header, spacing and
    brand colours (<x-vendor-layout>), plus a small tab row for the portal's own pages. The vendor comes from the
    existing `vendor` guard; this component adds no auth of its own (the `developer.access` middleware does that).

    Use inside `@extends('layouts.vendor')`, as every vendor page does.
--}}
@props([
    'title' => 'Developer API',
    'subtitle' => null,
    'tab' => null, // dashboard | applications | docs | assistant
    'back' => null, // ['url' => ..., 'label' => ...] breadcrumb-style link above the heading
])

@php
    $vendor = auth('vendor')->user();
    $tabs = [
        'dashboard' => ['Dashboard', route('developers.dashboard')],
        'applications' => ['Applications', route('developers.applications.index')],
        'docs' => ['Documentation', route('developers.docs.index')],
    ];
    if (app(\App\Services\Ai\Developer\DeveloperAssistant::class)->isAvailable()) {
        $tabs['assistant'] = ['Assistant', route('developers.assistant')];
    }
@endphp

@push('styles')
    {{-- Keyboard focus for full-width list rows: an inset outline, because the card clips an outside one. --}}
    <style>.dev-row:focus-visible { outline: 2px solid var(--color-brand-violet); outline-offset: -2px; background: #f5f3ff; }</style>
@endpush

<x-vendor-layout :vendor="$vendor" :title="$title" :subtitle="$subtitle" active="developer-access">
    <div class="space-y-6">
        @if (session('status') || session('success'))
            <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl text-sm" role="status">{{ session('status') ?? session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-xl text-sm" role="alert">
                <ul class="list-disc ml-5 space-y-1">
                    @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        <nav class="flex flex-wrap gap-2 border-b border-gray-200 pb-2" aria-label="Developer portal">
            @foreach ($tabs as $key => [$label, $url])
                <a href="{{ $url }}" @if ($tab === $key) aria-current="page" @endif
                   class="px-4 py-2 text-sm font-medium rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-violet {{ $tab === $key ? 'text-brand-violet bg-violet-50' : 'text-gray-600 hover:text-brand-violet' }}">{{ $label }}</a>
            @endforeach
        </nav>

        @if ($back)
            <a href="{{ $back['url'] }}" class="inline-flex items-center text-sm text-gray-600 hover:text-brand-violet">&larr; {{ $back['label'] }}</a>
        @endif

        {{ $slot }}
    </div>
</x-vendor-layout>
