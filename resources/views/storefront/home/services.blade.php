    {{-- ============================================================
         Services
         ============================================================ --}}
    @php $svc = $cms->section('home', 'services'); @endphp
    <section id="services" style="background-color: var(--x4-canvas-soft); padding: 72px 0;">
        <div class="max-w-6xl mx-auto px-5">
            <x-storefront.reveal class="max-w-xl mb-10">
                <x-storefront.eyebrow>{{ $svc['eyebrow'] }}</x-storefront.eyebrow>

                <h2 class="x4-display-xl mt-4 mb-3" style="color: var(--x4-ink);">
                    {{ $cms->accent($svc['title']) }}
                </h2>

                <p class="x4-body-lg" style="color: var(--x4-ink-sec);">
                    {{ $svc['description'] }}
                </p>
            </x-storefront.reveal>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                @foreach ($serviceCards as $i => $card)
                    <x-storefront.reveal :delay="$i * 75" class="flex flex-col">
                        <x-storefront.service-card
                            :name="$card['name']"
                            :description="$card['description']"
                            :icon="$card['icon']"
                            :badge="$card['badge']"
                            :href="$card['href']"
                        />
                    </x-storefront.reveal>
                @endforeach
            </div>
        </div>
    </section>
