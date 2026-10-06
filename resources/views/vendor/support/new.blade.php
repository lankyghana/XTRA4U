@extends('layouts.vendor')

@section('title', 'New support request - XTRA4U')

@section('content')
@php
    $preselected = $categories->firstWhere('slug', $prefillCategory);
    $config = [
        'messages' => [],
        'sendUrl' => route('vendor.support.store'),
        'canReply' => true,
        'maxImages' => config('support.images.max_per_message'),
        'categories' => $categories->map(fn ($c) => [
            'id' => $c->id, 'name' => $c->name, 'related_types' => $c->related_types ?? [], 'common_issues' => $c->common_issues ?? [],
        ])->values(),
        // related_* only pre-fill what the server already resolved for THIS vendor;
        // the server re-verifies ownership again on submit.
        'extra' => [
            'category_id' => $preselected?->id ?? '',
            'subject' => '',
            'related_type' => $prefill['type'] ?? '',
            'related_id' => $prefill['id'] ?? '',
        ],
    ];
@endphp
<x-vendor-layout :vendor="$vendor" title="Support" subtitle="Tell us what you need help with" active="support">
    <div class="space-y-3">
        <a href="{{ route('vendor.support.index') }}" class="text-sm text-gray-600 hover:underline">&larr; All requests</a>

        <div class="bg-white rounded-xl shadow overflow-hidden">
            <x-support.thread :config="$config" realm="vendor" :hide-messages="true">
                <x-slot:before>
                    <div class="p-4 space-y-4 border-b border-gray-100">
                        <h1 class="text-lg font-semibold text-gray-900">New support request</h1>

                        <div>
                            <label for="support-category" class="block text-sm font-medium text-gray-700">What is this about?</label>
                            <select id="support-category" x-model="extra.category_id" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                                <option value="">Choose a category…</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div x-show="(categories.find(c => c.id == extra.category_id)?.common_issues || []).length" x-cloak>
                            <p class="text-sm font-medium text-gray-700 mb-1">Common issues <span class="font-normal text-gray-400">(optional shortcuts)</span></p>
                            <div class="flex flex-wrap gap-2">
                                <template x-for="issue in (categories.find(c => c.id == extra.category_id)?.common_issues || [])" :key="issue">
                                    <button type="button" @click="extra.subject = issue; if (!text.trim()) text = issue"
                                            :class="extra.subject === issue ? 'bg-violet-100 border-brand-violet text-violet-900' : 'bg-white border-gray-300 text-gray-700'"
                                            class="px-3 py-1.5 text-xs rounded-full border" x-text="issue"></button>
                                </template>
                            </div>
                        </div>

                        @if ($prefill)
                            <div class="rounded-lg bg-violet-50 border border-violet-200 px-3 py-2 text-sm text-violet-900">
                                About: <strong>{{ $prefill['label'] }}</strong>
                                <button type="button" class="ml-2 text-xs underline" @click="extra.related_type = ''; extra.related_id = ''; $el.parentElement.remove()">Remove</button>
                            </div>
                        @else
                            @if (request()->hasAny(['related_type', 'related_id']))
                                <p class="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">We could not find that record on your account, so it was not attached.</p>
                            @endif
                            <div x-data="{ type: '', options: [], async load() {
                                    this.options = []; extra.related_id = ''; extra.related_type = this.type;
                                    if (!this.type) return;
                                    const r = await fetch('{{ route('vendor.support.related') }}?type=' + encodeURIComponent(this.type), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                                    if (r.ok) this.options = (await r.json()).options;
                                } }" class="grid gap-2 sm:grid-cols-2">
                                <div>
                                    <label for="support-related-type" class="block text-sm font-medium text-gray-700">Link a record <span class="font-normal text-gray-400">(optional)</span></label>
                                    <select id="support-related-type" x-model="type" @change="load()" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                                        <option value="">No linked record</option>
                                        @foreach ($relatedTypes as $slug => $label)
                                            <option value="{{ $slug }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div x-show="options.length" x-cloak>
                                    <label for="support-related-id" class="block text-sm font-medium text-gray-700">Which one?</label>
                                    <select id="support-related-id" x-model="extra.related_id" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                                        <option value="">Choose…</option>
                                        <template x-for="o in options" :key="o.id"><option :value="o.id" x-text="o.label"></option></template>
                                    </select>
                                </div>
                            </div>
                        @endif

                        <p class="text-xs text-gray-500">Write your own message below. The shortcuts above only save typing.</p>
                    </div>
                </x-slot:before>
            </x-support.thread>
        </div>
    </div>
</x-vendor-layout>
@endsection
