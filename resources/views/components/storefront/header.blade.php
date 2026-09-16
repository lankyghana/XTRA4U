{{--
    Public marketplace header.

    Scoped to the storefront homepage; the rest of the application keeps
    using `components/navigation.blade.php`. Every destination below is an
    existing named route.
--}}
{{--
    `showVendorLinks` gates the "Vendor Login" / "Become a Vendor" nav items.
    They are only meant to surface on the main homepage — every other page
    that shares this header (vendor storefronts, checkout, static pages,
    etc.) leaves this false and gets neither link.

    `showDashboardButton` gates the "Vendor Dashboard" nav item. Only the
    vendor storefront page (vendor_store.blade.php) passes this, and only
    when the authenticated vendor guard's id matches the store being
    viewed — see StorefrontController::showVendorStore(). It is a
    convenience shortcut back to the dashboard, not an authorization check;
    the dashboard route itself stays behind its own middleware.
--}}
@props(['shopUrl', 'showVendorLinks' => false, 'showDashboardButton' => false])

@php
    $navLinks = [
        ['label' => 'Home', 'href' => route('storefront.index')],
        ['label' => 'Results Checker', 'href' => route('result-checkers.entry')],
        ['label' => 'Retrieve PIN', 'href' => route('order.status')],
        ['label' => 'About', 'href' => route('about')],
    ];
@endphp

<header
    x-data="x4Header"
    class="fixed inset-x-0 top-0 z-50 transition-all duration-200"
    style="background-color: var(--x4-canvas);"
    :style="scrolled
        ? 'background-color: var(--x4-canvas); border-bottom: 1px solid var(--x4-hairline); box-shadow: var(--x4-shadow-1);'
        : 'background-color: var(--x4-canvas); border-bottom: 1px solid transparent;'"
>
    <div class="max-w-6xl mx-auto px-5 h-16 flex items-center justify-between gap-8">
        <a href="{{ route('storefront.index') }}" aria-label="XTRA4U home">
            <x-storefront.logo />
        </a>

        <nav class="hidden md:flex items-center gap-7" aria-label="Primary">
            @foreach ($navLinks as $link)
                <a
                    href="{{ $link['href'] }}"
                    class="x4-body-md x4-link"
                >{{ $link['label'] }}</a>
            @endforeach
        </nav>

        <div class="hidden md:flex items-center gap-4">
            @if ($showVendorLinks)
                <a
                    href="{{ route('vendor.login.form') }}"
                    class="x4-body-md x4-link"
                >Vendor Login</a>

                <x-storefront.btn :href="route('vendor.request.form')" variant="outline">
                    Become a Vendor
                </x-storefront.btn>
            @endif

            @if ($showDashboardButton)
                <x-storefront.btn :href="route('vendor.dashboard')" variant="outline">
                    Vendor Dashboard
                </x-storefront.btn>
            @endif

            <x-storefront.btn :href="$shopUrl" variant="primary">
                Buy Now
            </x-storefront.btn>
        </div>

        <button
            type="button"
            class="md:hidden p-2"
            style="color: var(--x4-ink);"
            @click="toggleMobile()"
            :aria-expanded="mobileOpen ? 'true' : 'false'"
            aria-controls="x4-mobile-nav"
        >
            <span class="sr-only" x-text="mobileOpen ? 'Close menu' : 'Open menu'">Open menu</span>
            <x-storefront.icon name="menu" class="w-5 h-5" x-show="!mobileOpen" />
            <x-storefront.icon name="close" class="w-5 h-5" x-show="mobileOpen" x-cloak />
        </button>
    </div>

    <div
        id="x4-mobile-nav"
        class="md:hidden px-6 pb-5"
        style="background-color: var(--x4-canvas); border-top: 1px solid var(--x4-hairline);"
        x-show="mobileOpen"
        x-collapse
        x-cloak
    >
        @foreach ($navLinks as $link)
            <a
                href="{{ $link['href'] }}"
                class="x4-body-md block py-3"
                style="color: var(--x4-ink); border-bottom: 1px solid var(--x4-hairline);"
            >{{ $link['label'] }}</a>
        @endforeach

        @if ($showVendorLinks)
            <a
                href="{{ route('vendor.login.form') }}"
                class="x4-body-md block py-3"
                style="color: var(--x4-ink); border-bottom: 1px solid var(--x4-hairline);"
            >Vendor Portal</a>
        @endif

        <div class="flex flex-col gap-3 mt-4">
            @if ($showVendorLinks)
                <x-storefront.btn :href="route('vendor.request.form')" variant="outline" class="justify-center">
                    Become a Vendor
                </x-storefront.btn>
            @endif
            @if ($showDashboardButton)
                <x-storefront.btn :href="route('vendor.dashboard')" variant="outline" class="justify-center">
                    Vendor Dashboard
                </x-storefront.btn>
            @endif
            <x-storefront.btn :href="$shopUrl" variant="primary" class="justify-center">
                Buy Now
            </x-storefront.btn>
        </div>
    </div>
</header>
