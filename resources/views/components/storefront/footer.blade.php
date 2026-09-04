{{--
    Public marketplace footer.

    Scoped to the storefront homepage. Only destinations that resolve to a
    real route are listed — the design's placeholder links (/contact,
    /support, /vendors) are mapped onto the routes this application
    actually serves rather than shipped as dead anchors.
--}}
{{--
    `showVendorLinks` gates the "Become a Vendor" / "Vendor Login" entries in
    the "For Vendors" column below. Only the main homepage passes true — every
    other page sharing this footer (vendor storefronts, checkout, static
    pages, etc.) keeps them out.
--}}
@props(['shopUrl', 'showVendorLinks' => false])

@php
    // The platform's public WhatsApp channel, as already used by
    // components/whatsapp-widget.blade.php.
    $whatsappChannel = 'https://whatsapp.com/channel/0029Vb6ZXJuL7UVQeZ7L5D3v';

    // Add real profile URLs here and the icons appear automatically.
    $socialLinks = array_filter([
        ['icon' => 'whatsapp', 'label' => 'WhatsApp channel', 'href' => $whatsappChannel],
    ]);

    $columns = [
        'Quick Links' => [
            ['label' => 'Home', 'href' => route('storefront.index')],
            ['label' => 'Services', 'href' => $shopUrl],
            ['label' => 'Results Checker', 'href' => route('result-checkers.entry')],
            ['label' => 'Order Status', 'href' => route('order.status')],
            ['label' => 'About Us', 'href' => route('about')],
        ],
        'For Vendors' => array_filter([
            $showVendorLinks ? ['label' => 'Become a Vendor', 'href' => route('vendor.request.form')] : null,
            $showVendorLinks ? ['label' => 'Vendor Login', 'href' => route('vendor.login.form')] : null,
            ['label' => 'Vendor Dashboard', 'href' => route('vendor.dashboard')],
            ['label' => 'Support', 'href' => $whatsappChannel],
        ]),
        'Legal' => [
            ['label' => 'Privacy Policy', 'href' => route('privacy')],
            ['label' => 'Terms of Service', 'href' => route('terms')],
        ],
    ];
@endphp

<footer id="site-footer" style="background-color: var(--x4-canvas); border-top: 1px solid var(--x4-hairline); padding: 56px 20px 28px;">
    <x-storefront.reveal from="fade">
        <div class="max-w-6xl mx-auto">
            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-10 mb-12">
                <div>
                    <x-storefront.logo />

                    <p class="x4-body-md mt-4" style="color: var(--x4-ink-mute);">
                        Ghana's digital services marketplace. Where trust meets value.
                    </p>

                    @if (count($socialLinks))
                        <div class="flex gap-2.5 mt-5">
                            @foreach ($socialLinks as $social)
                                <a
                                    href="{{ $social['href'] }}"
                                    aria-label="{{ $social['label'] }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="w-8 h-8 flex items-center justify-center x4-link x4-link-accent"
                                    style="background-color: var(--x4-canvas-soft); border-radius: var(--x4-r-sm); border: 1px solid var(--x4-hairline);"
                                >
                                    <x-storefront.icon :name="$social['icon']" class="w-4 h-4" />
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>

                @foreach ($columns as $heading => $links)
                    <div>
                        <h4 style="font-size: 14px; font-weight: 400; color: var(--x4-ink); margin-bottom: 16px;">
                            {{ $heading }}
                        </h4>
                        <ul class="space-y-2.5">
                            @foreach ($links as $link)
                                <li>
                                    <a
                                        href="{{ $link['href'] }}"
                                        class="x4-caption x4-link x4-link-accent"
                                    >{{ $link['label'] }}</a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>

            <div
                class="flex flex-col sm:flex-row items-center justify-between gap-3 pt-6"
                style="border-top: 1px solid var(--x4-hairline);"
            >
                <p class="x4-caption" style="color: var(--x4-ink-mute);">
                    &copy; {{ now()->year }} XTRA4U. All rights reserved.
                </p>
                <p class="x4-caption" style="color: var(--x4-ink-mute);">
                    Developed by <span style="color: var(--x4-ink); font-weight: 400;">Lanky iTech Ghana</span>
                </p>
            </div>
        </div>
    </x-storefront.reveal>
</footer>
