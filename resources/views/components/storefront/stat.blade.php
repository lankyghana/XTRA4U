{{--
    Count-up statistic.

    Renders the final value as text so the number is correct and readable
    before (or without) JavaScript; the script then animates from zero.
--}}
@props([
    'value',
    'decimals' => 0,
    'prefix' => '',
    'suffix' => '',
])

@php
    $display = $decimals > 0
        ? number_format((float) $value, (int) $decimals)
        : number_format((float) $value);
@endphp

<span
    data-x4-count="{{ $value }}"
    data-x4-decimals="{{ $decimals }}"
    data-x4-prefix="{{ $prefix }}"
    data-x4-suffix="{{ $suffix }}"
    {{ $attributes }}
>{{ $prefix }}{{ $display }}{{ $suffix }}</span>
