@php $statsData = $cms->section('home', 'stats'); @endphp
    {{-- ============================================================
         Accepted payment networks + platform stats
         ============================================================ --}}
    <section style="background-color: var(--x4-canvas); border-top: 1px solid var(--x4-hairline); border-bottom: 1px solid var(--x4-hairline);">
        <div class="max-w-6xl mx-auto px-5 py-4 flex flex-wrap items-center justify-between gap-4">
            <p class="x4-caption" style="color: var(--x4-ink-mute);">Accepted payment networks:</p>

            <div class="x4-marquee-mask" style="flex: 1 1 160px; min-width: 0;">
                <div class="x4-marquee">
                    {{-- Tripled so the loop is seamless; the copies are decorative. --}}
                    @for ($pass = 0; $pass < 3; $pass++)
                        @foreach ($paymentNetworks as $network)
                            <div
                                class="flex items-center justify-center flex-shrink-0"
                                style="border-radius: 10px; overflow: hidden; background-color: {{ $network['bg'] }}; border: 1px solid var(--x4-hairline); width: 120px; height: 52px; box-shadow: var(--x4-shadow-1);"
                                @if ($pass > 0) aria-hidden="true" @endif
                            >
                                <img
                                    src="{{ $network['src'] }}"
                                    alt="{{ $pass === 0 ? $network['alt'] : '' }}"
                                    loading="lazy"
                                    style="width: 100%; height: 100%; object-fit: contain; padding: 6px 10px;"
                                >
                            </div>
                        @endforeach
                    @endfor
                </div>
            </div>

            <div class="hidden md:flex items-center gap-6">
                @foreach ($statsData['stats'] as $stat)
                    <div class="text-right">
                        <p class="x4-tnum" style="font-size: 18px; color: var(--x4-ink); font-weight: 300; line-height: 1;">
                            <x-storefront.stat
                                :value="$stat['value']"
                                :decimals="$stat['decimals']"
                                :suffix="$stat['suffix']"
                            />
                        </p>
                        <p class="x4-micro-cap" style="color: var(--x4-ink-mute); margin-top: 2px;">{{ $stat['label'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
