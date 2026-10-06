    {{-- ============================================================
         Closing call to action
         ============================================================ --}}
    @php $cta = $cms->section('home', 'cta'); @endphp
    <section class="relative overflow-hidden" style="padding: 96px 0;">
        <div class="x4-aurora absolute inset-0" aria-hidden="true" style="opacity: 0.65;"></div>

        <div class="relative max-w-6xl mx-auto px-5 grid lg:grid-cols-2 gap-10 lg:gap-16 items-center">
            <x-storefront.reveal from="up">
                <h2 class="x4-display-xl mb-4" style="color: var(--x4-ink);">{{ $cta['title'] }}</h2>

                <p class="x4-body-lg mb-8" style="color: var(--x4-ink-sec);">
                    {{ $cta['description'] }}
                </p>

                <div class="flex flex-wrap gap-3 mb-7">
                    <x-storefront.btn :href="$cms->link($cta['primary_link'], $shopUrl) ?: $shopUrl" variant="primary">
                        {{ $cta['primary_text'] }}
                        <x-storefront.icon name="arrow" class="w-4 h-4" />
                    </x-storefront.btn>
                    <x-storefront.btn :href="$cms->link($cta['secondary_link'], $shopUrl) ?: route('vendor.request.form')" variant="outline">
                        {{ $cta['secondary_text'] }}
                    </x-storefront.btn>
                </div>

                <p class="flex items-center gap-2">
                    <span aria-hidden="true" class="flex items-center gap-0.5">
                        @for ($i = 0; $i < 5; $i++)
                            <x-storefront.icon name="star" class="w-4 h-4" style="color: #f59e0b;" />
                        @endfor
                    </span>
                    <span class="x4-caption ml-1" style="color: var(--x4-ink-mute);">{{ $cta['rating_text'] }}</span>
                </p>
            </x-storefront.reveal>

            <x-storefront.reveal from="right" :delay="120" class="hidden lg:block">
                <div class="relative" style="border-radius: var(--x4-r-xl); overflow: hidden; height: 340px; box-shadow: var(--x4-shadow-3); background-color: var(--x4-canvas-soft);">
                    <img
                        src="{{ $cms->image($cta['image']) }}"
                        alt="{{ $cta['image_alt'] }}"
                        loading="lazy"
                        class="w-full h-full object-cover"
                    >
                    <div aria-hidden="true" class="absolute inset-0" style="background: linear-gradient(135deg, rgba(83,58,253,0.15), transparent);"></div>

                    <div class="absolute top-4 left-4">
                        <x-storefront.logo :on-dark="true" />
                    </div>

                    <div
                        class="absolute bottom-4 left-4 right-4 text-center py-3 px-4"
                        style="background-color: rgba(255,255,255,0.88); border-radius: 10px; backdrop-filter: blur(8px);"
                    >
                        <p style="font-size: 13px; font-weight: 400; color: var(--x4-ink);">
                            {{ $cta['image_caption'] }}
                        </p>
                    </div>
                </div>
            </x-storefront.reveal>
        </div>
    </section>
