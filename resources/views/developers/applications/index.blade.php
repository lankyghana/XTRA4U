@extends('layouts.developers')
@section('title', 'Applications')
@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-semibold">Applications</h1>
    <a href="{{ route('developers.applications.create') }}" class="rounded-lg bg-[#00942C] text-white px-4 py-2 text-sm font-medium">New application</a>
</div>
<div class="bg-white rounded-xl border border-gray-200">
    @forelse($applications as $a)
        <a href="{{ route('developers.applications.show', $a) }}" class="block px-4 py-4 border-b border-gray-100 hover:bg-gray-50">
            <div class="flex items-center justify-between">
                <span class="font-medium">{{ $a->name }}</span>
                <span class="text-xs rounded-full {{ $a->status === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }} px-2 py-0.5">{{ $a->status }}</span>
            </div>
            <div class="text-xs text-gray-500 mt-1">
                {{ $a->vendor_id ? 'Vendor-bound' : 'Marketplace' }} · {{ $a->environment }} · production access: {{ $a->production_status }} · {{ $a->active_keys_count }} active key(s)
            </div>
        </a>
    @empty
        <p class="px-4 py-8 text-center text-sm text-gray-500">No applications yet. Create one to get API keys.</p>
    @endforelse
</div>
@endsection
