{{--
    Storefront pill button.

    Renders an <a> when `href` is given and a <button> otherwise, so the
    element always matches its behaviour.

    variant : primary | outline | ghost
    hero    : squarer, larger CTA treatment used in the hero section
--}}
@props([
    'href' => null,
    'variant' => 'primary',
    'hero' => false,
    'type' => 'button',
])

@php
    $classes = ['x4-btn', 'x4-btn-' . $variant];

    if ($hero) {
        $classes[] = 'x4-btn-hero';
        $classes[] = $variant === 'primary' ? 'x4-btn-hero-primary' : 'x4-btn-hero-outline';
    }

    $classes = implode(' ', $classes);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
