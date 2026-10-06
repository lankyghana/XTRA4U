@php
    $h = $cms->section('home', 'hero');
    $slides = $cms->banners('home_hero');
@endphp
    {{-- ============================================================
         Hero
         ============================================================ --}}
    <section class="relative overflow-hidden" style="background: #fff; padding-top: 64px;">
        <div class="x4-hero-wash absolute inset-0" aria-hidden="true" style="pointer-events: none;"></div>

        <div class="relative max-w-6xl mx-auto px-5">
            <div class="grid lg:grid-cols-2 gap-10 lg:gap-14 items-center pb-12 lg:pb-14">

                {{-- Copy --}}
                <div class="order-2 lg:order-1 text-center lg:text-left">
                    <x-storefront.reveal from="up">
                        <span
                            class="inline-flex items-center gap-2 mb-5"
                            style="background-color: var(--x4-violet-soft); border-radius: var(--x4-r-pill); padding: 5px 14px;"
                        >
                            <span aria-hidden="true" style="width: 6px; height: 6px; border-radius: 9999px; background-color: var(--x4-violet); flex-shrink: 0;"></span>
                            <span style="font-size: 10px; font-weight: 500; color: var(--x4-violet); letter-spacing: 0.08em; text-transform: uppercase;">
                                {{ $h['badge'] }}
                            </span>
                        </span>

                        <h1 class="x4-display-xxl mb-5 lg:mb-6" style="color: var(--x4-ink-strong);">
                            {{ $cms->accent($h['title']) }}
                        </h1>

                        <p class="x4-body-lg mb-7 mx-auto lg:mx-0" style="color: var(--x4-ink-body); line-height: 1.65; max-width: 460px;">
                            {{ $h['description'] }}
                        </p>

                        <div class="flex flex-wrap justify-center lg:justify-start gap-3 mb-7">
                            <x-storefront.btn :href="$cms->link($h['primary_link'], $shopUrl) ?: $shopUrl" variant="primary" hero>
                                {{ $h['primary_text'] }}
                                <x-storefront.icon name="arrow" class="w-4 h-4" />
                            </x-storefront.btn>

                            <x-storefront.btn :href="$cms->link($h['secondary_link'], $shopUrl) ?: route('order.status')" variant="outline" hero>
                                {{ $h['secondary_text'] }}
                            </x-storefront.btn>
                        </div>

                        <ul class="flex flex-col items-center lg:items-start gap-2.5">
                            @foreach (array_column($h['points'], 'text') as $point)
                                <li class="flex items-center gap-2.5" style="font-size: 14px; color: var(--x4-ink-body);">
                                    <span
                                        aria-hidden="true"
                                        class="flex items-center justify-center flex-shrink-0"
                                        style="width: 20px; height: 20px; border-radius: 9999px; background-color: var(--x4-violet-soft);"
                                    >
                                        <x-storefront.icon name="check" class="w-3 h-3" style="color: var(--x4-violet);" />
                                    </span>
                                    {{ $point }}
                                </li>
                            @endforeach
                        </ul>
                    </x-storefront.reveal>
                </div>

                {{-- Image carousel (managed under Content > Banners) --}}
                @if (count($slides))
                <div
                    class="order-1 lg:order-2 relative"
                    x-data="x4Hero({{ count($slides) }})"
                    @mouseenter="pause()"
                    @mouseleave="resume()"
                    @focusin="pause()"
                    @focusout="resume()"
                    role="group"
                    aria-roledescription="carousel"
                    aria-label="XTRA4U highlights"
                >
                    <div class="x4-slider">
                        @foreach ($slides as $i => $slide)
                            <img
                                src="{{ $slide['image'] }}"
                                alt="{{ $slide['title'] }}"
                                width="1100"
                                height="619"
                                @if ($i === 0) fetchpriority="high" @endif
                                {{-- Object syntax, not a string: it toggles `is-active` by name,
                                     so Alpine can also strip the static one off the first slide.
                                     A string binding only removes classes it previously added. --}}
                                class="x4-slide{{ $i === 0 ? ' is-active' : '' }}"
                                :class="{ 'is-active': active === {{ $i }} }"
                                :aria-hidden="active === {{ $i }} ? 'false' : 'true'"
                                @if ($i > 0) aria-hidden="true" @endif
                            >
                        @endforeach

                        <div
                            aria-hidden="true"
                            class="absolute inset-0"
                            style="background: linear-gradient(to top, rgba(17,24,39,0.15) 0%, transparent 40%); pointer-events: none;"
                        ></div>

                        <div
                            class="x4-float absolute flex items-center gap-2"
                            style="top: 14px; right: 14px; background-color: var(--x4-violet); border-radius: 10px; padding: 6px 13px; box-shadow: 0 4px 14px rgba(91,61,245,0.35);"
                        >
                            <span class="x4-pulse-dot" aria-hidden="true" style="width: 7px; height: 7px; border-radius: 9999px; background-color: #4ade80;"></span>
                            <span style="color: #fff; font-size: 12px; font-weight: 400;">Platform Live</span>
                        </div>

                        @if (count($slides) > 1)
                            <div class="absolute flex items-center" style="bottom: 8px; left: 50%; transform: translateX(-50%);">
                                @foreach ($slides as $i => $slide)
                                    <button
                                        type="button"
                                        class="x4-slider-dot"
                                        @click="go({{ $i }})"
                                        :aria-current="active === {{ $i }} ? 'true' : 'false'"
                                        @if ($i === 0) aria-current="true" @endif
                                    >
                                        <span class="sr-only">Show image {{ $i + 1 }} of {{ count($slides) }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
                @endif
            </div>
        </div>


        @include('storefront.home.trust')
    </section>
