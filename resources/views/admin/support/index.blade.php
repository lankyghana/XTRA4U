@extends('layouts.admin')

@section('title', 'Support - Admin Portal')

@section('content')
@php
    $listQuery = array_filter($filters, fn ($v) => $v !== null && $v !== '');
    $conversation = $selected['conversation'] ?? null;
    if ($selected) {
        $config = [
            'messages' => $selected['page']['messages'],
            'lastId' => $selected['page']['last_id'],
            'hasMoreBefore' => $selected['page']['has_more_before'],
            'canReply' => ! $conversation->isClosed(),
            'messagesUrl' => route('admin.support.messages', $conversation->id),
            'sendUrl' => route('admin.support.send', $conversation->id),
            'quickUrl' => route('admin.support.quick-replies.available', ['category' => $conversation->category_id]),
            'pollSeconds' => $pollSeconds,
            'maxImages' => config('support.images.max_per_message'),
        ];
    }
@endphp
<x-admin-layout title="Support" subtitle="Vendor messages, oldest waiting first" active="support">
    @if (session('status'))
        <div class="mb-3 bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="mb-3 bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg">{{ $errors->first() }}</div>
    @endif

    <div class="grid gap-4 lg:grid-cols-[22rem_1fr] lg:h-[calc(100vh-11rem)]">
        {{-- LEFT: inbox --}}
        <section class="{{ $selected ? 'hidden lg:flex' : 'flex' }} flex-col bg-white rounded-xl shadow overflow-hidden min-h-0">
            <form method="GET" action="{{ route('admin.support.index') }}" class="p-3 space-y-2 border-b border-gray-100">
                <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search vendor, phone, order #, reference, message…"
                       class="w-full rounded-lg border-gray-300 text-sm">
                <div class="grid grid-cols-2 gap-2">
                    <select name="view" class="rounded-lg border-gray-300 text-sm">
                        @foreach (['all' => 'All', 'unread' => 'Unread'] + $statuses as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['view'] ?? 'all') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <select name="category" class="rounded-lg border-gray-300 text-sm">
                        <option value="">All categories</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected(($filters['category'] ?? '') == $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                    <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="rounded-lg border-gray-300 text-sm" aria-label="Activity from">
                    <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="rounded-lg border-gray-300 text-sm" aria-label="Activity to">
                    <select name="sort" class="rounded-lg border-gray-300 text-sm col-span-2">
                        <option value="queue" @selected(($filters['sort'] ?? 'queue') === 'queue')>Queue: longest waiting first</option>
                        <option value="newest" @selected(($filters['sort'] ?? '') === 'newest')>Newest activity</option>
                        <option value="oldest" @selected(($filters['sort'] ?? '') === 'oldest')>Oldest activity</option>
                    </select>
                </div>
                <div class="flex items-center gap-4 text-sm text-gray-700">
                    <label class="inline-flex items-center gap-1"><input type="checkbox" name="has_image" value="1" @checked(! empty($filters['has_image']))> Has image</label>
                    <label class="inline-flex items-center gap-1"><input type="checkbox" name="has_voice" value="1" @checked(! empty($filters['has_voice']))> Has voice</label>
                    <button class="inline-flex items-center justify-center rounded-lg font-medium shadow-sm transition focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 px-3 py-1.5 text-xs bg-brand-violet text-white hover:bg-brand-violet-deep focus:ring-brand-violet ml-auto">Filter</button>
                </div>
                @if (! empty($filters['vendor']))
                    <input type="hidden" name="vendor" value="{{ $filters['vendor'] }}">
                @endif
            </form>

            <div class="flex-1 overflow-y-auto divide-y divide-gray-100">
                @forelse ($conversations as $c)
                    <a href="{{ route('admin.support.show', ['conversationId' => $c->id] + $listQuery) }}"
                       class="block px-3 py-3 hover:bg-gray-50 {{ $conversation && $conversation->id === $c->id ? 'bg-violet-50' : '' }}">
                        <div class="flex items-start justify-between gap-2">
                            <p class="text-sm {{ $c->unread_count > 0 ? 'font-semibold' : 'font-medium' }} text-gray-900 truncate">{{ $c->vendor?->name ?? 'Deleted vendor' }}</p>
                            @if ($c->unread_count > 0)
                                <span class="shrink-0 inline-flex items-center justify-center min-w-5 h-5 px-1.5 rounded-full text-xs font-semibold text-white bg-brand-violet">{{ $c->unread_count }}</span>
                            @endif
                        </div>
                        <p class="text-xs text-gray-500 truncate">{{ $c->category?->name }}@if ($c->related_label) · {{ $c->related_label }}@endif</p>
                        <p class="text-sm text-gray-600 truncate mt-0.5">@if ($c->last_message_sender === 'admin')You: @endif{{ $c->last_message_preview }}</p>
                        <div class="flex items-center gap-2 mt-1">
                            <x-support.status-badge :status="$c->status" :label="$c->statusLabel()" />
                            @if ($c->waiting_since)
                                <span class="text-[11px] text-amber-700">waiting {{ $c->waiting_since->diffForHumans(null, true) }}</span>
                            @endif
                            <span class="ml-auto text-[11px] text-gray-400">{{ $c->last_message_at?->diffForHumans(short: true) }}</span>
                        </div>
                    </a>
                @empty
                    <p class="p-8 text-center text-sm text-gray-500">No conversations match.</p>
                @endforelse
            </div>
            <div class="p-2 border-t border-gray-100 text-sm">{{ $conversations->links() }}</div>
            <div class="px-3 py-2 border-t border-gray-100 text-xs">
                <a href="{{ route('admin.support.quick-replies.index') }}" class="text-brand-violet hover:underline">Manage quick replies</a>
            </div>
        </section>

        {{-- RIGHT: conversation --}}
        <section class="{{ $selected ? 'flex' : 'hidden lg:flex' }} flex-col bg-white rounded-xl shadow overflow-hidden min-h-0">
            @if ($selected)
                <div class="px-4 py-3 border-b border-gray-100 space-y-2">
                    <a href="{{ route('admin.support.index', $listQuery) }}" class="lg:hidden text-sm text-gray-600">&larr; Inbox</a>
                    <div class="flex items-start justify-between gap-3 flex-wrap">
                        <div class="min-w-0">
                            <h2 class="font-semibold text-gray-900">{{ $conversation->subject }}</h2>
                            <p class="text-sm text-gray-600">
                                {{ $conversation->vendor?->name ?? 'Deleted vendor' }}
                                @if ($conversation->vendor?->phone_number) · {{ $conversation->vendor->phone_number }}@endif
                                · {{ $conversation->category?->name }}
                            </p>
                            @if ($conversation->related_label)
                                <p class="text-sm mt-0.5">
                                    Related:
                                    @if ($selected['relatedUrl'])
                                        <a href="{{ $selected['relatedUrl'] }}" class="text-brand-violet underline" target="_blank" rel="noopener">{{ $conversation->related_label }}</a>
                                    @else
                                        <span class="font-medium">{{ $conversation->related_label }}</span>
                                    @endif
                                </p>
                            @endif
                        </div>
                        <div class="flex items-center gap-2">
                            <x-support.status-badge :status="$conversation->status" :label="$conversation->statusLabel()" />
                            @if (! $conversation->isResolved() && ! $conversation->isClosed())
                                <form method="POST" action="{{ route('admin.support.resolve', $conversation->id) }}">@csrf
                                    <button class="inline-flex items-center justify-center rounded-lg font-medium shadow-sm transition focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 px-3 py-1.5 text-xs bg-[#00942C] text-white hover:bg-[#009633] focus:ring-[#00942C]">Resolve</button></form>
                            @endif
                            @if (! $conversation->isClosed())
                                <form method="POST" action="{{ route('admin.support.close', $conversation->id) }}" onsubmit="return confirm('Close this conversation? The vendor will need to start a new request.')">@csrf
                                    <button class="inline-flex items-center justify-center rounded-lg font-medium shadow-sm transition focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 px-3 py-1.5 text-xs border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 focus:ring-brand-violet">Close</button></form>
                            @endif
                            @if ($conversation->isResolved() || $conversation->isClosed())
                                <form method="POST" action="{{ route('admin.support.reopen', $conversation->id) }}">@csrf
                                    <button class="inline-flex items-center justify-center rounded-lg font-medium shadow-sm transition focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 px-3 py-1.5 text-xs bg-brand-violet text-white hover:bg-brand-violet-deep focus:ring-brand-violet">Reopen</button></form>
                            @endif
                        </div>
                    </div>
                    @if ($conversation->resolved_at)
                        <p class="text-xs text-gray-500">Resolved {{ $conversation->resolved_at->format('d M Y, H:i') }}</p>
                    @endif
                    <details class="text-xs text-gray-500">
                        <summary class="cursor-pointer">History</summary>
                        <ul class="mt-1 space-y-0.5">
                            @foreach ($selected['events'] as $event)
                                <li>{{ $event->created_at?->format('d M, H:i') }} · {{ str_replace('_', ' ', $event->event) }}@if ($event->to_status) → {{ \App\Models\SupportConversation::STATUSES[$event->to_status] ?? $event->to_status }}@endif · {{ $event->actor_type }} #{{ $event->actor_id }}</li>
                            @endforeach
                        </ul>
                    </details>
                </div>

                <div class="flex-1 min-h-0 flex flex-col">
                    <x-support.thread :config="$config" realm="admin" :quick="true" />
                </div>
            @else
                <div class="m-auto p-10 text-center">
                    <p class="font-medium text-gray-700">Select a conversation</p>
                    <p class="text-sm text-gray-500 mt-1">Conversations waiting longest for an admin appear first.</p>
                </div>
            @endif
        </section>
    </div>
</x-admin-layout>
@endsection
