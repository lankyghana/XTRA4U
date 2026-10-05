    {{-- ============================================================
         Featured service: results checker PINs
         ============================================================ --}}
    @php $f = $cms->section('home', 'featured'); @endphp
    <section style="background-color: var(--x4-canvas); border-top: 1px solid var(--x4-hairline); padding: 0 0 80px;">
        <div class="max-w-6xl mx-auto px-5">
            <div class="relative mb-8 sm:mb-12 overflow-hidden" style="border-radius: 0 0 20px 20px; height: clamp(180px, 40vw, 300px);">
                <img
                    src="{{ $cms->image($f['image']) }}"
                    alt="{{ $f['image_alt'] }}"
                    loading="lazy"
                    class="w-full h-full object-cover"
                    style="object-position: center 30%;"
                >
                <div aria-hidden="true" class="absolute inset-0" style="background: linear-gradient(to bottom, rgba(28,30,84,0.2), rgba(28,30,84,0.72));"></div>

                <div class="absolute bottom-0 left-0 right-0 p-6 sm:p-8 flex items-end justify-between gap-6">
                    <div>
                        <x-storefront.eyebrow class="mb-3">{{ $f['eyebrow'] }}</x-storefront.eyebrow>
                        <h2 class="x4-display-lg" style="color: #fff;">
                            {{ $cms->accent($f['title'], 'var(--x4-primary-sub)') }}
                        </h2>
                    </div>

                    <div class="hidden md:block text-right flex-shrink-0">
                        <p class="x4-tnum" style="color: #fff; font-size: 32px; font-weight: 300; letter-spacing: -0.64px;">
                            {{ $f['side_title'] }}
                        </p>
                        <p style="color: rgba(255,255,255,0.55); font-size: 13px;">{{ $f['side_caption'] }}</p>
                    </div>
                </div>
            </div>

            <div class="grid md:grid-cols-2 gap-12 items-start">
                <x-storefront.reveal from="left">
                    <p class="x4-body-lg mb-6" style="color: var(--x4-ink-mute);">
                        {{ $f['description'] }}
                    </p>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-8">
                        @foreach ($f['benefits'] as $benefit)
                            <div class="flex items-start gap-2.5">
                                <x-storefront.icon name="check" class="w-4 h-4 flex-shrink-0 mt-0.5" style="color: var(--x4-primary);" />
                                <div>
                                    <p style="font-size: 14px; font-weight: 400; color: var(--x4-ink);">{{ $benefit['label'] }}</p>
                                    <p class="x4-caption mt-0.5" style="color: var(--x4-ink-mute);">{{ $benefit['desc'] }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <x-storefront.btn :href="$cms->link($f['primary_link'], $shopUrl) ?: route('result-checkers.entry')" variant="primary">
                            {{ $f['primary_text'] }}
                        </x-storefront.btn>
                        <x-storefront.btn :href="$cms->link($f['secondary_link'], $shopUrl) ?: route('result-checkers.status')" variant="outline">
                            {{ $f['secondary_text'] }}
                        </x-storefront.btn>
                    </div>
                </x-storefront.reveal>

                <x-storefront.reveal from="right" :delay="100">
                    {{-- Illustration of a completed order. Hidden from
                         assistive tech so the sample values are never read
                         out as if they were the visitor's own order. --}}
                    <div style="background-color: var(--x4-canvas-cream); border-radius: var(--x4-r-xl); padding: 32px; box-shadow: var(--x4-shadow-1);">
                        <p class="sr-only">Illustration of a completed results checker order.</p>

                        <div aria-hidden="true">
                            <div class="flex items-center justify-between mb-5">
                                <span style="font-size: 14px; font-weight: 400; color: var(--x4-ink);">Results Checker PIN</span>
                                <x-storefront.eyebrow>WAEC 2025</x-storefront.eyebrow>
                            </div>

                            <div style="background-color: var(--x4-canvas); border-radius: 10px; padding: 20px 16px; text-align: center; margin-bottom: 16px; border: 1px solid var(--x4-hairline);">
                                <p class="x4-caption mb-2" style="color: var(--x4-ink-mute);">Your PIN</p>
                                <p class="x4-tnum" style="font-size: 26px; letter-spacing: 0.14em; color: var(--x4-ink);">&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;</p>
                                <p style="font-size: 11px; color: var(--x4-ink-mute); margin-top: 6px;">Securely stored &mdash; retrieve anytime</p>
                            </div>

                            @foreach ([
                                ['k' => 'Exam Board', 'v' => 'WAEC 2025', 'accent' => false],
                                ['k' => 'Status', 'v' => 'Delivered', 'accent' => true],
                                ['k' => 'Order ID', 'v' => 'XTR-00419', 'accent' => false],
                                ['k' => 'Payment', 'v' => 'MTN MoMo', 'accent' => false],
                            ] as $row)
                                <div class="flex justify-between py-2" style="border-bottom: 1px solid rgba(0,0,0,0.06);">
                                    <span class="x4-caption" style="color: var(--x4-ink-mute);">{{ $row['k'] }}</span>
                                    <span class="x4-caption x4-tnum" style="color: {{ $row['accent'] ? '#16a34a' : 'var(--x4-ink)' }};">{{ $row['v'] }}</span>
                                </div>
                            @endforeach
                        </div>

                        <x-storefront.btn :href="route('result-checkers.status')" variant="primary" class="w-full mt-5">
                            Retrieve My PIN
                        </x-storefront.btn>
                    </div>
                </x-storefront.reveal>
            </div>
        </div>
    </section>
