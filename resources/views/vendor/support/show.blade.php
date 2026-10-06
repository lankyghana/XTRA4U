@extends('layouts.vendor')

@section('title', 'Support - XTRA4U')

@section('content')
@php
    $config = [
        'messages' => $page['messages'],
        'lastId' => $page['last_id'],
        'hasMoreBefore' => $page['has_more_before'],
        'canReply' => $canReply,
        'messagesUrl' => route('vendor.support.messages', $conversation->id),
        'sendUrl' => route('vendor.support.send', $conversation->id),
        'pollSeconds' => $pollSeconds,
        'maxImages' => config('support.images.max_per_message'),
    ];
@endphp
<x-vendor-layout :vendor="$vendor" title="Support" subtitle="Message the XTRA4U team" active="support">
    <div class="space-y-3">
        <a href="{{ route('vendor.support.index') }}" class="text-sm text-gray-600 hover:underline">&larr; All requests</a>

        <div class="bg-white rounded-xl shadow overflow-hidden flex flex-col">
            <div class="px-4 py-3 border-b border-gray-100">
                <div class="flex items-center gap-2 flex-wrap">
                    <h1 class="font-semibold text-gray-900">{{ $conversation->subject }}</h1>
                    <x-support.status-badge :status="$conversation->status" :label="$conversation->statusLabel()" />
                </div>
                <p class="text-xs text-gray-500 mt-0.5">
                    {{ $conversation->category?->name !== $conversation->subject ? $conversation->category?->name : '' }}@if ($conversation->related_label){{ $conversation->category?->name !== $conversation->subject ? ' · ' : '' }}{{ $conversation->related_label }}@endif
                </p>
                @if ($conversation->isResolved())
                    <p class="text-xs text-green-700 mt-1">Marked resolved. Replying within {{ config('support.reopen_days') }} days reopens this request.</p>
                @endif
            </div>

            <x-support.thread :config="$config" realm="vendor" />
        </div>
    </div>
</x-vendor-layout>
@endsection
