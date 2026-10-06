@props([
    'label',
    'value',
    'hint' => null,
    'tone' => 'neutral', // neutral | success | warning | danger | brand
    'href' => null,
])

@php
    $accent = match ($tone) {
        'success' => 'bg-green-500',
        'warning' => 'bg-amber-500',
        'danger' => 'bg-red-500',
        'brand' => 'bg-brand-violet',
        default => 'bg-gray-300',
    };
    $tag = $href ? 'a' : 'div';
    $extra = $href ? ' transition hover:border-brand-violet/40 hover:shadow-md' : '';
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" @endif
    {{ $attributes->merge(['class' => 'relative block overflow-hidden rounded-xl border border-gray-200 bg-white p-4 shadow-sm'.$extra]) }}>
    <span class="absolute inset-y-0 left-0 w-1 {{ $accent }}" aria-hidden="true"></span>
    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $label }}</p>
    <p class="mt-1.5 text-2xl font-semibold tracking-tight text-gray-900 tabular-nums">{{ $value }}</p>
    @if ($hint)
        <p class="mt-1 text-xs text-gray-500">{{ $hint }}</p>
    @endif
</{{ $tag }}>
