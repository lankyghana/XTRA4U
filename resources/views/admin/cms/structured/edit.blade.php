@php
    $title = $key === 'home' ? 'Homepage' : 'About page';
    $sectionKeys = array_keys($sections);
    $seoVal = fn (string $f) => old($f, $seo[$f] ?? null);
@endphp

<x-admin-layout :title="$title" subtitle="Edit the content of each section. Changes are saved as drafts until you publish." :active="'cms-'.$key">
    <x-slot name="actions">
        <x-button :href="route('admin.cms.site.preview', $key)" variant="outline" size="sm" target="_blank" rel="noopener">Preview draft</x-button>
        <x-button :href="route('admin.cms.site.history', $key)" variant="ghost" size="sm">History</x-button>
        <x-button :href="$key === 'home' ? url('/') : route('about')" variant="ghost" size="sm" target="_blank" rel="noopener">View live</x-button>
    </x-slot>

    @include('admin.cms._flash', ['hideErrors' => true])

    {{-- Publish bar --}}
    <div class="mb-6 flex flex-col gap-3 rounded-xl border p-4 sm:flex-row sm:items-center sm:justify-between {{ $pending ? 'border-brand-violet/30 bg-brand-violet-soft' : 'border-gray-200 bg-white' }}" role="status">
        <div class="text-sm">
            @if ($pending)
                <p class="font-semibold text-brand-violet-deep">You have unpublished changes</p>
                <p class="text-brand-violet-deep/80">Visitors still see the last published version. Preview the draft, then publish.</p>
            @else
                <p class="font-semibold text-gray-900">Everything is published</p>
                <p class="text-gray-500">
                    @if ($page->published_at) Last published {{ $page->published_at->diffForHumans() }}@if ($publisher) by {{ $publisher }}@endif. @endif
                </p>
            @endif
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($pending)
                <form method="POST" action="{{ route('admin.cms.site.discard', $key) }}" onsubmit="return confirm('Discard every unpublished change on this page?')">@csrf
                    <x-button type="submit" variant="secondary" size="sm">Discard changes</x-button>
                </form>
            @endif
            <form method="POST" action="{{ route('admin.cms.site.publish', $key) }}">@csrf
                <x-button type="submit" variant="primary" size="sm" :disabled="! $pending">Publish changes</x-button>
            </form>
        </div>
    </div>

    <div class="space-y-4">
        @foreach ($sections as $sectionKey => $section)
            @php
                $def = $section['def'];
                $bag = $errors->getBag('section_'.$sectionKey);
                $hasErrors = $bag->any();
                $position = array_search($sectionKey, $sectionKeys, true);
            @endphp

            <section id="section-{{ $sectionKey }}" x-data="{ open: {{ $hasErrors ? 'true' : 'false' }} || location.hash === '#section-{{ $sectionKey }}' }"
                     class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center gap-3 px-4 py-3">
                    <button type="button" class="flex min-w-0 flex-1 items-center gap-2 text-left" @click="open = !open" :aria-expanded="open.toString()">
                        <svg class="h-4 w-4 flex-shrink-0 text-gray-400 transition-transform" :class="open ? 'rotate-90' : ''" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-semibold text-gray-900">{{ $def['label'] }}</span>
                            <span class="block truncate text-xs text-gray-500">{{ $def['help'] }}</span>
                        </span>
                    </button>

                    @if ($section['has_draft'])
                        <x-admin.status status="processing" label="Draft saved" />
                    @endif
                    @if ($def['hideable'] && ! $section['visible'])
                        <x-admin.status status="cancelled" label="Hidden" />
                    @endif

                    <div class="flex items-center gap-1.5">
                        @if ($def['movable'])
                            @foreach (['up' => 'Move up', 'down' => 'Move down'] as $dir => $label)
                                <form method="POST" action="{{ route('admin.cms.site.move', [$key, $sectionKey]) }}">@csrf
                                    <input type="hidden" name="direction" value="{{ $dir }}">
                                    <button type="submit" class="rounded-md border border-gray-200 p-1.5 text-gray-500 hover:bg-gray-100" aria-label="{{ $label }}" title="{{ $label }}">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $dir === 'up' ? 'M5 15l7-7 7 7' : 'M19 9l-7 7-7-7' }}"/></svg>
                                    </button>
                                </form>
                            @endforeach
                        @endif
                        @if ($def['hideable'])
                            <form method="POST" action="{{ route('admin.cms.site.visibility', [$key, $sectionKey]) }}">@csrf
                                <input type="hidden" name="visible" value="{{ $section['visible'] ? 0 : 1 }}">
                                <button type="submit" class="rounded-md border border-gray-200 px-2.5 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-100">{{ $section['visible'] ? 'Hide' : 'Show' }}</button>
                            </form>
                        @endif
                    </div>
                </div>

                <form x-show="open" x-cloak method="POST" action="{{ route('admin.cms.site.section', [$key, $sectionKey]) }}" class="border-t border-gray-100 bg-gray-50/50 p-4" novalidate>
                    @csrf
                    @method('PUT')
                    @if ($hasErrors)
                        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert">
                            <p class="font-medium">This section was not saved:</p>
                            <ul class="mt-1 list-inside list-disc">@foreach ($bag->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
                        </div>
                    @endif
                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach ($def['fields'] as $name => $field)
                            @include('admin.cms.structured._field', [
                                'field' => $field,
                                'name' => $name,
                                'value' => $hasErrors ? data_get(old('data'), $name, $section['values'][$name]) : $section['values'][$name],
                                'bag' => $bag,
                                'id' => $sectionKey.'-'.$name,
                            ])
                        @endforeach
                    </div>
                    <div class="mt-5 flex justify-end">
                        <x-button type="submit" variant="primary" size="sm">Save section draft</x-button>
                    </div>
                </form>
            </section>
        @endforeach

        {{-- Search and social --}}
        <section id="seo" class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="px-4 py-3">
                <h2 class="text-sm font-semibold text-gray-900">Search and social sharing</h2>
                <p class="text-xs text-gray-500">Title and description shown in search results and when the page is shared. Empty fields use the defaults shown.</p>
            </div>
            <form method="POST" action="{{ route('admin.cms.site.seo', $key) }}" class="space-y-4 border-t border-gray-100 p-4" novalidate>
                @csrf
                @method('PUT')
                @if ($errors->any() && ! $errors->getBag('default')->isEmpty())
                    <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert">
                        <ul class="list-inside list-disc">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
                    </div>
                @endif
                @include('admin.cms._seo-fields', ['val' => $seoVal, 'titlePlaceholder' => $defaultSeo['seo_title'] ?? '', 'descriptionPlaceholder' => $defaultSeo['meta_description'] ?? ''])
                <div class="flex justify-end">
                    <x-button type="submit" variant="primary" size="sm">Save search settings draft</x-button>
                </div>
            </form>
        </section>
    </div>

    @once
        @push('scripts')
            <script>
                function cmsRepeater(opts) {
                    let counter = 0;
                    const withKey = (row) => Object.assign({ _k: ++counter }, row);
                    return {
                        rows: opts.rows.map(withKey), max: opts.max, min: opts.min,
                        add() { if (this.rows.length < this.max) this.rows.push(withKey(JSON.parse(JSON.stringify(opts.blank)))); },
                        remove(i) { if (this.rows.length > this.min) this.rows.splice(i, 1); },
                    };
                }
            </script>
        @endpush
    @endonce
</x-admin-layout>
