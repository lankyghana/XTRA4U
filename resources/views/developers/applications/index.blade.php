@extends('layouts.vendor')
@section('title', 'Applications - XTRA4U')
@section('content')
<x-developers.shell title="Applications" subtitle="Each application has its own API keys, webhooks and request log" tab="applications">
    <x-developers.section title="Your applications" :flush="true">
        <x-slot:actions>
            <x-button :href="route('developers.applications.create')" size="sm">New application</x-button>
        </x-slot:actions>

        <div class="divide-y divide-gray-100">
            @forelse($applications as $a)
                <a href="{{ route('developers.applications.show', $a) }}" class="flex items-center justify-between gap-3 px-4 sm:px-5 py-4 hover:bg-gray-50 dev-row">
                    <div class="min-w-0">
                        <p class="font-medium text-gray-900 truncate">{{ $a->name }}</p>
                        <p class="text-xs text-gray-500 mt-0.5">{{ $a->active_keys_count }} active key(s) &middot; created {{ $a->created_at->toDateString() }}</p>
                    </div>
                    <x-badge size="sm" :variant="$a->status === 'active' ? 'completed' : 'failed'">{{ $a->status }}</x-badge>
                </a>
            @empty
                <div class="p-10 text-center">
                    <p class="text-gray-700 font-medium">No applications yet</p>
                    <p class="text-sm text-gray-500 mt-1">Create an application to get API keys.</p>
                    <x-button :href="route('developers.applications.create')" class="mt-4">Create an application</x-button>
                </div>
            @endforelse
        </div>
    </x-developers.section>
</x-developers.shell>
@endsection
