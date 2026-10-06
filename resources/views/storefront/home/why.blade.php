    {{-- ============================================================
         Why XTRA4U
         ============================================================ --}}
    @php $why = $cms->section('home', 'why'); @endphp
    <section style="background-color: var(--x4-canvas); padding: 80px 0 96px;">
        <div class="max-w-6xl mx-auto px-5 grid lg:grid-cols-2 gap-14 lg:gap-14 items-center">
            <x-storefront.reveal from="left" class="relative">
                <div class="relative">
                    <div style="border-radius: var(--x4-r-xl); overflow: hidden; height: clamp(280px, 55vw, 460px); background-color: var(--x4-canvas-soft); box-shadow: var(--x4-shadow-2);">
                        <img
                            src="{{ $cms->image($why['image']) }}"
                            alt="{{ $why['image_alt'] }}"
                            loading="lazy"
                            class="w-full h-full object-cover"
                            style="object-position: center 18%;"
                        >
                        <div aria-hidden="true" class="absolute inset-0" style="background: linear-gradient(to top, rgba(28,30,84,0.4) 0%, transparent 60%);"></div>
                    </div>

                    <div
                        class="absolute right-0 lg:-right-6 -bottom-4 lg:-bottom-6 px-4 py-3 sm:px-5 sm:py-4"
                        style="background-color: var(--x4-canvas); border-radius: 14px; box-shadow: var(--x4-shadow-2); border: 1px solid var(--x4-hairline);"
                    >
                        <p class="x4-micro-cap mb-2" style="color: var(--x4-ink-mute);">Platform Health</p>

                        @foreach ($why['metrics'] as $metric)
                            <div class="flex justify-between gap-8 py-1">
                                <span class="x4-caption" style="color: var(--x4-ink-mute);">{{ $metric['label'] }}</span>
                                <span class="x4-tnum x4-caption" style="color: var(--x4-ink); font-weight: 400;">
                                    <x-storefront.stat
                                        :value="$metric['value']"
                                        :decimals="$metric['decimals']"
                                        :prefix="$metric['prefix']"
                                        :suffix="$metric['suffix']"
                                    />
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </x-storefront.reveal>

            <x-storefront.reveal from="right" :delay="80">
                <x-storefront.eyebrow>{{ $why['eyebrow'] }}</x-storefront.eyebrow>

                <h2 class="x4-display-xl mt-4 mb-4" style="color: var(--x4-ink);">
                    {{ $cms->accent($why['title'], 'var(--x4-primary)') }}
                </h2>

                <p class="x4-body-lg mb-8" style="color: var(--x4-ink-mute);">
                    {{ $why['description'] }}
                </p>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    @foreach ($why['features'] as $i => $feature)
                        <x-storefront.reveal :delay="$i * 70">
                            <div style="background-color: var(--x4-canvas-soft); border: 1px solid var(--x4-hairline); border-radius: 10px; padding: 18px; height: 100%;">
                                <x-storefront.icon :name="$feature['icon']" class="w-5 h-5 mb-2" style="color: var(--x4-primary);" />
                                <h3 style="font-size: 14px; font-weight: 400; color: var(--x4-ink); margin-bottom: 4px;">{{ $feature['title'] }}</h3>
                                <p style="font-size: 13px; color: var(--x4-ink-sec); line-height: 1.4;">{{ $feature['body'] }}</p>
                            </div>
                        </x-storefront.reveal>
                    @endforeach
                </div>
            </x-storefront.reveal>
        </div>
    </section>
