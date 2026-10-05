        {{-- Trust strip --}}
        @php $trust = $cms->section('home', 'trust'); @endphp
        @if ($cms->sectionVisible('home', 'trust'))
        <div style="background-color: var(--x4-brand-dark);">
            <div class="max-w-6xl mx-auto px-5">
                <div class="grid grid-cols-2 lg:grid-cols-4" style="border-top: 1px solid rgba(255,255,255,0.06);">
                    @foreach ($trust['items'] as $i => $item)
                        <x-storefront.reveal from="up" :delay="$i * 90" class="flex">
                            <div class="flex items-center gap-3 py-5 px-4">
                                <span
                                    aria-hidden="true"
                                    class="flex items-center justify-center flex-shrink-0"
                                    style="width: 38px; height: 38px; border-radius: 9999px; border: 1.5px solid rgba(255,255,255,0.22); color: rgba(255,255,255,0.85);"
                                >
                                    <x-storefront.icon :name="$item['icon']" class="w-4 h-4" />
                                </span>
                                <div>
                                    <p style="color: #fff; font-size: 13px; font-weight: 400; line-height: 1.3;">{{ $item['title'] }}</p>
                                    <p style="color: rgba(255,255,255,0.5); font-size: 11px; margin-top: 2px;">{{ $item['desc'] }}</p>
                                </div>
                            </div>
                        </x-storefront.reveal>
                    @endforeach
                </div>
            </div>
        </div>
        @endif
