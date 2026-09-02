{{--
    XTRA4U wordmark.

    `onDark` swaps the ink colours for use over the dark brand panel.
--}}
@props(['onDark' => false])

<span
    {{ $attributes->merge(['class' => 'inline-flex items-center gap-2 select-none']) }}
    style="font-weight: 400; font-size: 17px; letter-spacing: -0.3px;"
>
    <span
        class="inline-flex items-center justify-center text-white"
        style="width: 28px; height: 28px; border-radius: 6px; background-color: var(--x4-primary); font-size: 10px; font-weight: 700; letter-spacing: 0.02em;"
        aria-hidden="true"
    >X4U</span>
    <span style="color: {{ $onDark ? '#fff' : 'var(--x4-ink)' }};">
        XTRA<span style="color: {{ $onDark ? 'rgba(255,255,255,0.55)' : 'var(--x4-primary-soft)' }};">4U</span>
    </span>
</span>
