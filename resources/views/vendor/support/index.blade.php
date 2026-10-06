@extends('layouts.vendor')

@section('title', 'Support - XTRA4U')

@section('content')
<x-vendor-layout :vendor="$vendor" title="Support" subtitle="Message the XTRA4U team about orders, payments and more" active="support">
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-gray-900">Your support requests</h1>
            <a href="{{ route('vendor.support.new') }}" class="inline-flex items-center px-4 py-2 text-sm font-semibold text-white rounded-lg bg-brand-violet hover:opacity-90">New request</a>
        </div>

        <div class="bg-white rounded-xl shadow divide-y divide-gray-100">
            @forelse ($conversations as $conversation)
                <a href="{{ route('vendor.support.show', $conversation->id) }}" class="flex items-start gap-3 p-4 hover:bg-gray-50">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <p class="font-medium text-gray-900 truncate">{{ $conversation->subject }}</p>
                            <x-support.status-badge :status="$conversation->status" :label="$conversation->statusLabel()" />
                        </div>
                        <p class="text-xs text-gray-500 mt-0.5">
                            {{ $conversation->category?->name }}@if ($conversation->related_label) · {{ $conversation->related_label }}@endif
                        </p>
                        <p class="text-sm text-gray-600 mt-1 truncate">
                            @if ($conversation->last_message_sender === 'vendor')You: @endif{{ $conversation->last_message_preview }}
                        </p>
                    </div>
                    <div class="text-right shrink-0">
                        <p class="text-xs text-gray-400">{{ $conversation->last_message_at?->diffForHumans(short: true) }}</p>
                        @if ($conversation->unread_count > 0)
                            <span class="inline-flex mt-1 items-center justify-center min-w-5 h-5 px-1.5 rounded-full text-xs font-semibold text-white bg-brand-violet">{{ $conversation->unread_count }}</span>
                        @endif
                    </div>
                </a>
            @empty
                <div class="p-10 text-center">
                    <p class="text-gray-700 font-medium">No support requests yet</p>
                    <p class="text-sm text-gray-500 mt-1">Need help with an order, payment or withdrawal? Send us a message.</p>
                    <a href="{{ route('vendor.support.new') }}" class="inline-flex mt-4 px-4 py-2 text-sm font-semibold text-white rounded-lg bg-brand-violet">Start a request</a>
                </div>
            @endforelse
        </div>

        {{ $conversations->links() }}
    </div>
</x-vendor-layout>
@endsection
