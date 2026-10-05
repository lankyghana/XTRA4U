    {{-- ============================================================
         Vendor recruitment
         ============================================================ --}}
    @php $v = $cms->section('home', 'vendors'); @endphp
    <section id="vendors" style="background-color: var(--x4-canvas-soft); padding: 80px 0;">
        <div class="max-w-6xl mx-auto px-5">
            <div class="grid lg:grid-cols-2 overflow-hidden" style="border-radius: var(--x4-r-xl); box-shadow: var(--x4-shadow-2);">
                <x-storefront.reveal from="left">
                    <div class="flex flex-col justify-center sm:p-12 h-full" style="background-color: var(--x4-brand-dark); padding: 36px 28px;">
                        <span
                            class="x4-micro-cap self-start mb-5 px-2 py-1"
                            style="background-color: rgba(83,58,253,0.3); color: var(--x4-primary-sub); border-radius: var(--x4-r-pill);"
                        >{{ $v['badge'] }}</span>

                        <h2 class="x4-display-xl mb-4" style="color: #fff;">{{ $v['title'] }}</h2>

                        <p class="x4-body-lg mb-8" style="color: rgba(255,255,255,0.6);">
                            {{ $v['description'] }}
                        </p>

                        <div class="grid grid-cols-3 gap-4 mb-8 pb-8" style="border-bottom: 1px solid rgba(255,255,255,0.08);">
                            @foreach ($v['stats'] as $stat)
                                <div>
                                    <p class="x4-tnum" style="font-size: 20px; color: #fff; font-weight: 300; letter-spacing: -0.4px;">
                                        <x-storefront.stat
                                            :value="$stat['value']"
                                            :decimals="$stat['decimals']"
                                            :prefix="$stat['prefix']"
                                            :suffix="$stat['suffix']"
                                        />
                                    </p>
                                    <p style="font-size: 11px; color: rgba(255,255,255,0.45); margin-top: 2px;">{{ $stat['label'] }}</p>
                                </div>
                            @endforeach
                        </div>

                        <div class="flex flex-wrap gap-3">
                            <x-storefront.btn :href="$cms->link($v['primary_link'], $shopUrl) ?: route('vendor.request.form')" variant="primary">
                                {{ $v['primary_text'] }}
                                <x-storefront.icon name="arrow" class="w-4 h-4" />
                            </x-storefront.btn>
                            <x-storefront.btn :href="$cms->link($v['secondary_link'], $shopUrl) ?: route('vendor.login.form')" variant="ghost">
                                {{ $v['secondary_text'] }}
                            </x-storefront.btn>
                        </div>
                    </div>
                </x-storefront.reveal>

                <x-storefront.reveal from="right" :delay="100">
                    {{-- Stacked on small screens this row is short, so the crop
                         is pulled down from the very top to keep the subject
                         in frame at every width. --}}
                    <div class="relative min-h-80 lg:min-h-full h-full" style="background-color: var(--x4-canvas-soft);">
                        <img
                            src="{{ $cms->image($v['image']) }}"
                            alt="{{ $v['image_alt'] }}"
                            loading="lazy"
                            class="w-full h-full object-cover absolute inset-0"
                            style="object-position: center 22%;"
                        >
                        <div aria-hidden="true" class="absolute inset-0" style="background: linear-gradient(to right, rgba(28,30,84,0.35), transparent);"></div>

                        {{-- Illustrative dashboard preview. --}}
                        <div
                            class="absolute bottom-6 right-6 left-6 px-4 py-4"
                            style="background-color: rgba(255,255,255,0.92); border-radius: var(--x4-r-lg); backdrop-filter: blur(12px); border: 1px solid rgba(255,255,255,0.6);"
                        >
                            <p class="sr-only">Illustration of the vendor dashboard.</p>
                            <div aria-hidden="true">
                                <div class="flex items-center justify-between mb-3">
                                    <span style="font-size: 13px; font-weight: 400; color: var(--x4-ink);">Vendor Dashboard</span>
                                    <span class="x4-micro-cap px-2 py-0.5" style="background-color: #dcfce7; color: #166534; border-radius: var(--x4-r-pill);">Active</span>
                                </div>

                                @foreach ([['Orders Today', '24'], ['Revenue (GH₵)', '1,840'], ['Rating', '4.9 / 5.0']] as $row)
                                    <div class="flex justify-between py-1.5" style="border-bottom: 1px solid var(--x4-hairline);">
                                        <span class="x4-caption" style="color: var(--x4-ink-mute);">{{ $row[0] }}</span>
                                        <span class="x4-tnum x4-caption" style="color: var(--x4-ink); font-weight: 400;">{{ $row[1] }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </x-storefront.reveal>
            </div>
        </div>
    </section>
