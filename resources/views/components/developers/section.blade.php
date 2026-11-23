{{--
    A titled card, the same `bg-white rounded-xl border border-gray-200` surface the vendor dashboard uses.
    flush : the body is a list of rows that run edge to edge (rows draw their own dividers); otherwise padded.
    tone  : default | sandbox (amber, for simulated/test-only data)
    The optional `actions` slot sits on the right of the title row.
--}}
@props(['title' => null, 'flush' => false, 'tone' => 'default'])
@php $border = ['default' => 'border-gray-200', 'sandbox' => 'border-amber-200', 'secret' => 'border-amber-300'][$tone] ?? 'border-gray-200'; @endphp

<section {{ $attributes->merge(['class' => 'bg-white rounded-xl border overflow-hidden '.$border]) }}>
    @if ($title || isset($actions))
        <div class="px-4 sm:px-5 py-3 border-b flex items-center justify-between gap-3 flex-wrap {{ $tone === 'sandbox' ? 'border-amber-200 bg-amber-50' : 'border-gray-200' }}">
            <h2 class="font-semibold text-gray-900">{{ $title }}</h2>
            @isset($actions)<div class="flex items-center gap-2 flex-wrap">{{ $actions }}</div>@endisset
        </div>
    @endif
    <div class="{{ $flush ? '' : 'p-4 sm:p-5' }}">{{ $slot }}</div>
</section>
