{{--
    Service category card.

    Presentation only — `name`, `description` and `href` come from the
    application's own storefront category configuration and routes, so the
    grid grows and shrinks with whatever the platform actually offers.
--}}
@props([
    'name',
    'description' => null,
    'href',
    'icon' => 'wifi',
    'badge' => null,
])

<div class="x4-service-card">
    @if ($badge)
        <span
            class="x4-micro-cap absolute top-5 right-5 px-2.5 py-1"
            style="background-color: var(--x4-violet-soft); color: var(--x4-violet); border-radius: var(--x4-r-pill); font-weight: 500;"
        >{{ $badge }}</span>
    @endif

    <div class="x4-service-icon mb-5">
        <x-storefront.icon :name="$icon" class="w-5 h-5" />
    </div>

    <h3 style="font-size: 18px; font-weight: 500; color: var(--x4-ink-strong); margin-bottom: 8px; line-height: 1.3; letter-spacing: -0.2px;">
        {{ $name }}
    </h3>

    @if ($description)
        <p style="font-size: 14px; color: var(--x4-ink-soft); line-height: 1.6; margin-bottom: 20px; flex: 1;">
            {{ $description }}
        </p>
    @else
        <div style="flex: 1;"></div>
    @endif

    {{-- The whole card lifts on hover, but only this link is focusable,
         which keeps the keyboard path identical to the pointer path. --}}
    <a href="{{ $href }}" class="x4-service-link">
        Browse<span class="sr-only"> {{ $name }}</span>
        <x-storefront.icon name="arrow" class="w-3.5 h-3.5" />
    </a>
</div>
