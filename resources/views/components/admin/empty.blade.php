@props([
    'title',
    'description' => null,
])

{{-- Quiet empty state. Works inside a table cell (<td colspan>) or a card. --}}
<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center gap-1 px-4 py-8 text-center']) }}>
    <span class="mb-1 flex h-10 w-10 items-center justify-center rounded-full bg-brand-violet-soft text-brand-violet" aria-hidden="true">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-4l-2 3H10l-2-3H4"/></svg>
    </span>
    <p class="text-sm font-medium text-gray-900">{{ $title }}</p>
    @if ($description)
        <p class="max-w-sm text-sm text-gray-500">{{ $description }}</p>
    @endif
    @if ($slot->isNotEmpty())
        <div class="mt-3">{{ $slot }}</div>
    @endif
</div>
