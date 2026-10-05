    {{-- ============================================================
         How it works
         ============================================================ --}}
    @php $how = $cms->section('home', 'how'); @endphp
    <section style="background-color: var(--x4-canvas-soft); padding: 80px 0;">
        <div class="max-w-6xl mx-auto px-5">
            <x-storefront.reveal class="text-center mb-14">
                <x-storefront.eyebrow>{{ $how['eyebrow'] }}</x-storefront.eyebrow>
                <h2 class="x4-display-xl mt-4 mb-3" style="color: var(--x4-ink);">{{ $how['title'] }}</h2>
                <p class="x4-body-lg" style="color: var(--x4-ink-sec);">{{ $how['description'] }}</p>
            </x-storefront.reveal>

            <div class="relative grid md:grid-cols-3 gap-6">
                <div
                    aria-hidden="true"
                    class="hidden md:block absolute"
                    style="top: 20px; left: calc(16.67% + 40px); right: calc(16.67% + 40px); height: 1px; background-color: var(--x4-hairline);"
                ></div>

                @foreach ($how['steps'] as $i => $step)
                    <x-storefront.reveal :delay="$i * 110">
                        <div style="background-color: var(--x4-canvas); border: 1px solid var(--x4-hairline); border-radius: var(--x4-r-lg); padding: 28px; box-shadow: var(--x4-shadow-1); height: 100%;">
                            <div
                                class="x4-tnum w-10 h-10 flex items-center justify-center mb-5 relative z-10"
                                style="border-radius: 8px; font-size: 14px; font-weight: 400;
                                    background-color: {{ $i === 0 ? 'var(--x4-primary)' : 'var(--x4-canvas-soft)' }};
                                    color: {{ $i === 0 ? '#fff' : 'var(--x4-ink-mute)' }};
                                    {{ $i === 0 ? 'box-shadow: 0 0 0 4px var(--x4-primary-sub);' : '' }}"
                            >{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</div>

                            <h3 class="x4-heading-md mb-2" style="color: var(--x4-ink);">{{ $step['title'] }}</h3>
                            <p class="x4-body-md" style="color: var(--x4-ink-sec);">{{ $step['body'] }}</p>
                        </div>
                    </x-storefront.reveal>
                @endforeach
            </div>

            <x-storefront.reveal :delay="330" class="text-center mt-10">
                <x-storefront.btn :href="$cms->link($how['button_link'], $shopUrl) ?: $shopUrl" variant="primary">
                    {{ $how['button_text'] }}
                    <x-storefront.icon name="arrow" class="w-4 h-4" />
                </x-storefront.btn>
            </x-storefront.reveal>
        </div>
    </section>
