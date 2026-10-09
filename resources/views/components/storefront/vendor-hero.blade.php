{{--
    Vendor storefront hero banner, shared by the vendor store and the pages
    that live under it (e.g. /store/{vendor}/utility-bills), so every page of
    a store carries the same identity: eyebrow, headline, vendor code,
    verified badge and contact chip. Pass no vendor for a platform page
    (the chips are then omitted).

    Usage:
      <x-storefront.vendor-hero :vendor="$vendor" eyebrow="Utility Bills">
          <x-slot:title>Shop with <span ...>{{ $vendor->name }}</span></x-slot:title>
          Lead paragraph.
      </x-storefront.vendor-hero>
--}}
@props(['vendor' => null, 'eyebrow' => 'Vendor Storefront', 'title'])

<section class="relative overflow-hidden" style="background: #fff;">
    <div class="x4-hero-wash absolute inset-0" aria-hidden="true" style="pointer-events: none;"></div>

    <div class="relative max-w-6xl mx-auto px-5 py-10 sm:py-14 text-center">
        <x-storefront.reveal from="up" class="max-w-2xl mx-auto">
            <x-storefront.eyebrow>{{ $eyebrow }}</x-storefront.eyebrow>

            <h1 class="x4-display-xl mt-4 mb-3" style="color: var(--x4-ink-strong);">{{ $title }}</h1>

            <p class="x4-body-lg mb-5" style="color: var(--x4-ink-body);">{{ $slot }}</p>

            @if ($vendor)
                <div class="flex flex-wrap items-center justify-center gap-2.5">
                    <span
                        class="inline-flex items-center gap-2"
                        style="background-color: var(--x4-violet-soft); border-radius: var(--x4-r-pill); padding: 6px 14px;"
                    >
                        <span class="x4-micro-cap" style="color: var(--x4-violet);">Vendor Code</span>
                        <span class="x4-caption x4-tnum" style="color: var(--x4-violet); font-weight: 500;">{{ $vendor->vendor_code ?? 'N/A' }}</span>
                    </span>

                    @if ($vendor->is_approved)
                        <span
                            class="inline-flex items-center gap-1.5"
                            style="background-color: #dcfce7; color: #166534; border-radius: var(--x4-r-pill); padding: 6px 14px;"
                        >
                            <x-storefront.icon name="shield" class="w-3.5 h-3.5" />
                            <span class="x4-caption" style="font-weight: 500;">Verified Vendor</span>
                        </span>
                    @endif

                    @if ($vendor->phone_number)
                        <span
                            class="inline-flex items-center gap-1.5"
                            style="background-color: var(--x4-canvas-soft); border: 1px solid var(--x4-hairline); border-radius: var(--x4-r-pill); padding: 6px 14px;"
                        >
                            <x-storefront.icon name="phone" class="w-3.5 h-3.5" style="color: var(--x4-ink-mute);" />
                            <span class="x4-caption" style="color: var(--x4-ink-sec);">{{ $vendor->phone_number }}</span>
                        </span>
                    @endif
                </div>
            @endif
        </x-storefront.reveal>
    </div>
</section>
