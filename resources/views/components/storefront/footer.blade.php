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

    `showVendorDashboardLink` gates the "Vendor Dashboard" entry in that same
    column. It defaults to true everywhere except a vendor's own storefront
    (vendor_store.blade.php), which already surfaces a dashboard button in
    the header for the storefront owner and doesn't need it repeated here.
--}}
@props(['shopUrl', 'showVendorLinks' => false, 'showVendorDashboardLink' => true])

@php
    // Everything below is managed under Content in the admin dashboard: footer text,
    // contact details and social links (Site Settings) and the two link columns
    // (Navigation). Values are validated on save and re-validated here.
    $whatsappChannel = \App\Support\Cms\CmsLink::safe($cms->setting('contact.whatsapp_channel_url'));

    $socialLinks = [];
    if ($whatsappChannel) {
        $socialLinks[] = ['icon' => 'whatsapp', 'label' => 'WhatsApp channel', 'href' => $whatsappChannel];
    }
    $socialIcons = ['x' => 'twitter'];
    foreach ($cms->socialLinks() as $social) {
        $socialLinks[] = ['icon' => $socialIcons[$social['key']] ?? $social['key'], 'label' => $social['label'], 'href' => $social['url']];
    }

    $toColumn = fn (string $location) => array_map(
        fn ($item) => ['label' => $item['label'], 'href' => $item['url']],
        $cms->nav($location, $shopUrl)
    );

    $columns = [
        'Quick Links' => $toColumn('footer_quick'),
        'For Vendors' => array_filter([
            $showVendorLinks ? ['label' => 'Become a Vendor', 'href' => route('vendor.request.form')] : null,
            $showVendorLinks ? ['label' => 'Vendor Login', 'href' => route('vendor.login.form')] : null,
            $showVendorDashboardLink ? ['label' => 'Vendor Dashboard', 'href' => route('vendor.dashboard')] : null,
            $whatsappChannel ? ['label' => 'Support', 'href' => $whatsappChannel] : null,
        ]),
        'Legal' => $toColumn('footer_legal'),
    ];

    $contactLines = array_filter([
        'phone' => $cms->setting('contact.support_phone'),
        'email' => $cms->setting('contact.support_email'),
        'address' => $cms->setting('contact.business_address'),
        'hours' => $cms->setting('contact.working_hours'),
    ]);
    $footerCredit = $cms->setting('footer.credit_name');
@endphp

<footer id="site-footer" style="background-color: var(--x4-canvas); border-top: 1px solid var(--x4-hairline); padding: 56px 20px 28px;">
    <x-storefront.reveal from="fade">
        <div class="max-w-6xl mx-auto">
            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-10 mb-12">
                <div>
                    <x-storefront.logo />

                    <p class="x4-body-md mt-4" style="color: var(--x4-ink-mute);">
                        {{ $cms->setting('footer.description') }}
                    </p>

                    @if ($contactLines)
                        <ul class="x4-caption mt-4 space-y-1" style="color: var(--x4-ink-mute);">
                            @isset($contactLines['phone'])
                                <li><a class="x4-link x4-link-accent" href="tel:{{ preg_replace('/[^0-9+]/', '', $contactLines['phone']) }}">{{ $contactLines['phone'] }}</a></li>
                            @endisset
                            @isset($contactLines['email'])
                                <li><a class="x4-link x4-link-accent" href="mailto:{{ $contactLines['email'] }}">{{ $contactLines['email'] }}</a></li>
                            @endisset
                            @isset($contactLines['address'])
                                <li style="white-space: pre-line;">{{ $contactLines['address'] }}</li>
                            @endisset
                            @isset($contactLines['hours'])
                                <li>{{ $contactLines['hours'] }}</li>
                            @endisset
                        </ul>
                    @endif

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
                    &copy; {{ now()->year }} {{ $cms->setting('footer.copyright_name') }}. All rights reserved.
                </p>
                @if ($footerCredit)
                    <p class="x4-caption" style="color: var(--x4-ink-mute);">
                        Developed by <span style="color: var(--x4-ink); font-weight: 400;">{{ $footerCredit }}</span>
                    </p>
                @endif
            </div>
        </div>
    </x-storefront.reveal>
</footer>
