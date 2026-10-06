@props([
    'items',                         // [['label' => 'All', 'href' => '…', 'active' => bool, 'count' => int|null], …]
    'label' => 'Filter by status',
])

{{-- Segmented status filter. Plain links, so server-side filter semantics are untouched. --}}
<nav aria-label="{{ $label }}" {{ $attributes->merge(['class' => 'inline-flex max-w-full gap-1 overflow-x-auto rounded-lg bg-gray-100 p-1']) }}>
    @foreach ($items as $item)
        <a href="{{ $item['href'] }}"
           @if (! empty($item['active'])) aria-current="page" @endif
           class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md px-3 py-1.5 text-sm font-medium transition {{ ! empty($item['active']) ? 'bg-white text-brand-violet shadow-sm' : 'text-gray-600 hover:text-gray-900' }}">
            {{ $item['label'] }}
            @if (isset($item['count']))
                <span class="rounded-full bg-gray-200/70 px-1.5 text-[11px] tabular-nums text-gray-600">{{ $item['count'] }}</span>
            @endif
        </a>
    @endforeach
</nav>
