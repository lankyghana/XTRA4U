{{--
    Scroll-reveal wrapper.

    Adds the data attribute the storefront JS looks for. Without JS the
    content simply renders in place, fully visible.

    from  : up | left | right | fade
    delay : milliseconds to stagger the transition by
--}}
@props(['from' => 'up', 'delay' => 0])

<div
    data-x4-reveal="{{ $from }}"
    @if ($delay) style="--x4-reveal-delay: {{ (int) $delay }}ms" @endif
    {{ $attributes }}
>{{ $slot }}</div>
