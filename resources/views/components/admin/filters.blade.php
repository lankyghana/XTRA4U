@props([
    'action',
    'search' => null,       // current search text; pass '' (not null) to show the search box empty
    'placeholder' => 'Search',
    'name' => 'q',
    'hidden' => [],         // [key => value] carried across a search (e.g. the current status tab)
    'active' => null,       // force the Reset button on/off; default = search is filled or $hidden has values
    'resetUrl' => null,
])

{{--
    Standard admin filter bar:  [ Search ] [extra controls slot] [Apply] [Reset]
    A plain GET form, so backend filter semantics are unchanged.
--}}
@php
    $hidden = array_filter($hidden, fn ($v) => filled($v));
    $isActive = $active ?? (filled($search) || ! empty($hidden));
@endphp

<form method="GET" action="{{ $action }}" {{ $attributes->merge(['class' => 'flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center']) }}>
    @foreach ($hidden as $k => $v)
        <input type="hidden" name="{{ $k }}" value="{{ $v }}">
    @endforeach

    @if ($search !== null)
        <div class="relative w-full sm:w-80">
            <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 10.5a6.5 6.5 0 11-13 0 6.5 6.5 0 0113 0z"/></svg>
            <label for="admin-filter-{{ $name }}" class="sr-only">{{ $placeholder }}</label>
            <input id="admin-filter-{{ $name }}" type="search" name="{{ $name }}" value="{{ $search }}" placeholder="{{ $placeholder }}" class="w-full !pl-9">
        </div>
    @endif

    {{ $slot }}

    <div class="flex items-center gap-2">
        <x-button type="submit" variant="primary" size="sm">Apply</x-button>
        @if ($isActive)
            <x-button :href="$resetUrl ?? $action" variant="secondary" size="sm">Reset</x-button>
        @endif
    </div>
</form>
