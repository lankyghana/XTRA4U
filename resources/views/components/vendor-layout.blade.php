@props([
    'title' => 'Vendor Portal',
    'subtitle' => null,
    'active' => null,
])

@once
    @php
        if (! function_exists('vendor_nav_paths')) {
            function vendor_nav_paths(string $name): string
            {
                return match ($name) {
                    'dashboard' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2-2z" />'
                        . '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5a2 2 0 012-2h4a2 2 0 012 2v6h-8V5z" />',
                    'orders' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2" />'
                        . '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 7h6m-6 4h6" />',
                    'products' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10" />',
                    'withdrawals' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V4m0 12v4m8-10a8 8 0 11-16 0 8 8 0 0116 0z" />',
                    'analytics' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2z" />'
                        . '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 9a2 2 0 012-2h2a2 2 0 012 2v10m0 0a2 2 0 002 2h2a2 2 0 002-2V5a2 2 0 00-2-2h-2a2 2 0 00-2 2" />',
                    'settings' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />'
                        . '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />',
                    default => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />',
                };
            }
        }
    @endphp
@endonce

@php
    $navLinks = [
        [
            'key' => 'dashboard',
            'label' => 'Dashboard',
            'href' => route('vendor.dashboard'),
            'matches' => ['vendor.dashboard'],
        ],
        [
            'key' => 'orders',
            'label' => 'Orders',
            'href' => route('vendor.orders.index'),
            'matches' => ['vendor.orders.*'],
        ],
        [
            'key' => 'products',
            'label' => 'Products',
            'href' => route('vendor.products.index'),
            'matches' => ['vendor.products.*'],
        ],
        [
            'key' => 'withdrawals',
            'label' => 'Withdrawals',
            'href' => route('vendor.withdrawals.index'),
            'matches' => ['vendor.withdrawals.*'],
        ],
        [
            'key' => 'analytics',
            'label' => 'Analytics',
            'href' => '#',
            'matches' => [],
        ],
        [
            'key' => 'settings',
            'label' => 'Settings',
            'href' => '#',
            'matches' => [],
        ],
    ];

    foreach ($navLinks as &$link) {
        $link['isActive'] = (($active ?? null) === $link['key'])
            || (!empty($link['matches']) && request()->routeIs(...$link['matches']));
    }
    unset($link);

    $linkBaseClasses = 'group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors';
@endphp

<div x-data="{ openSidebar: false }" @keydown.window.escape="openSidebar = false" {{ $attributes->merge(['class' => 'flex min-h-screen bg-gray-50']) }}>
    <!-- Mobile sidebar -->
    <div x-cloak x-show="openSidebar" class="fixed inset-0 z-40 flex md:hidden" role="dialog" aria-modal="true">
        <div x-show="openSidebar" x-transition.opacity class="fixed inset-0 bg-black/30" @click="openSidebar = false"></div>
        <div x-show="openSidebar"
             x-transition:enter="transform transition ease-out duration-300"
             x-transition:enter-start="-translate-x-full opacity-0"
             x-transition:enter-end="translate-x-0 opacity-100"
             x-transition:leave="transform transition ease-in duration-200"
             x-transition:leave-start="translate-x-0 opacity-100"
             x-transition:leave-end="-translate-x-full opacity-0"
             class="relative flex w-4/5 max-w-sm flex-col bg-brand-deep-blue text-white shadow-2xl rounded-r-2xl">
            <div class="flex items-center justify-between px-4 pt-5 pb-4 border-b border-white/10">
                <div class="flex items-center">
                    <div class="w-8 h-8 bg-white rounded-lg flex items-center justify-center">
                        <span class="text-brand-deep-blue font-bold text-sm">X4U</span>
                    </div>
                    <span class="ml-2 text-lg font-bold">Vendor Portal</span>
                </div>
                <button type="button" class="rounded-md p-2 text-white/80 hover:text-white focus:outline-none focus:ring-2 focus:ring-white" @click="openSidebar = false" aria-label="Close sidebar">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div class="flex-1 overflow-y-auto px-4 py-6 space-y-1">
                @foreach ($navLinks as $link)
                    @php
                        $linkClasses = $link['isActive']
                            ? 'bg-white/20 text-white'
                            : 'text-blue-100 hover:text-white hover:bg-white/10';
                        $iconClasses = $link['isActive'] ? 'text-white' : 'text-blue-200 group-hover:text-white/80';
                    @endphp
                    <a href="{{ $link['href'] }}" class="{{ $linkBaseClasses }} {{ $linkClasses }}" @click="openSidebar = false">
                        <svg class="mr-3 h-5 w-5 {{ $iconClasses }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            {!! vendor_nav_paths($link['key']) !!}
                        </svg>
                        {{ $link['label'] }}
                    </a>
                @endforeach
            </div>
            <div class="border-t border-white/10 px-4 py-4">
                <div class="flex items-center">
                    <div class="w-8 h-8 bg-white rounded-full flex items-center justify-center">
                        <span class="text-brand-deep-blue font-medium text-sm">VD</span>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium">Vendor User</p>
                        <p class="text-xs text-blue-100">vendor@example.com</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Desktop sidebar -->
    <div class="hidden md:flex md:w-64 md:flex-col bg-brand-deep-blue text-white" aria-label="Vendor navigation">
        <div class="flex flex-col grow pt-5 overflow-y-auto">
            <div class="flex items-center shrink-0 px-4">
                <div class="w-8 h-8 bg-white rounded-lg flex items-center justify-center">
                    <span class="text-brand-deep-blue font-bold text-sm">X4U</span>
                </div>
                <span class="ml-2 text-lg font-bold">Vendor Portal</span>
            </div>
            <div class="mt-8 grow flex flex-col">
                <nav class="flex-1 px-2 pb-4 space-y-1">
                    @foreach ($navLinks as $link)
                        @php
                            $linkClasses = $link['isActive']
                                ? 'bg-white/15 text-white'
                                : 'text-blue-100 hover:text-white hover:bg-white/10';
                            $iconClasses = $link['isActive'] ? 'text-white' : 'text-blue-200 group-hover:text-white/80';
                        @endphp
                        <a href="{{ $link['href'] }}" class="{{ $linkBaseClasses }} {{ $linkClasses }}">
                            <svg class="mr-3 h-5 w-5 {{ $iconClasses }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                {!! vendor_nav_paths($link['key']) !!}
                            </svg>
                            {{ $link['label'] }}
                        </a>
                    @endforeach
                </nav>
                <div class="border-t border-white/10 p-4">
                    <div class="flex items-center">
                        <div class="w-8 h-8 bg-white rounded-full flex items-center justify-center">
                            <span class="text-brand-deep-blue font-medium text-sm">VD</span>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-white">Vendor User</p>
                            <p class="text-xs text-blue-200">vendor@example.com</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main content -->
    <div class="flex flex-col flex-1 overflow-hidden">
        <header class="bg-white shadow-sm border-b border-gray-200">
            <div class="flex flex-col gap-4 px-4 py-4 sm:px-6 lg:px-8 md:flex-row md:items-center md:justify-between">
                <div class="flex items-center gap-3">
                    <button class="md:hidden p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-brand-deep-blue" @click="openSidebar = true" aria-label="Open sidebar">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">{{ $title }}</h1>
                        @if ($subtitle)
                            <p class="text-sm text-gray-500">{{ $subtitle }}</p>
                        @endif
                    </div>
                </div>
                @isset($actions)
                    <div class="flex items-center gap-3">
                        {{ $actions }}
                    </div>
                @endisset
            </div>
        </header>

        <main class="flex-1 overflow-y-auto bg-gray-50">
            <div class="py-6 px-4 sm:px-6 lg:px-8">
                {{ $slot }}
            </div>
        </main>
    </div>
</div>
