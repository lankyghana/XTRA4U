@props([
    'title' => 'Vendor Portal',
    'subtitle' => null,
    'active' => null,
    'vendor' => null,
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
                    'fulfillment' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2V9" />'
                        . '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3h6l3 3" />'
                        . '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13l2 2 4-4" />',
                    'products' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10" />',
                    'wallet' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V4m0 12v4m8-10a8 8 0 11-16 0 8 8 0 0116 0z" />',
                    'analytics' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2z" />'
                        . '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 9a2 2 0 012-2h2a2 2 0 012 2v10m0 0a2 2 0 002 2h2a2 2 0 002-2V5a2 2 0 00-2-2h-2a2 2 0 00-2 2" />',
                    'affiliates' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />',
                    'marketplace' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />',
                    'reseller' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />',
                    'afa' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />',
                    'settings' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />'
                        . '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />',
                    default => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />',
                };
            }
        }
    @endphp
@endonce

@php
    $storefrontUrl = $vendor && $vendor->vendor_code ? route('storefront.vendor', ['vendor' => $vendor->vendor_code]) : null;

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
            'key' => 'fulfillment',
            'label' => 'Order Fulfillment',
            'href' => route('vendor.fulfillment.index'),
            'matches' => ['vendor.fulfillment.*'],
        ],
        [
            'key' => 'products',
            'label' => 'Products',
            'href' => route('vendor.products.index'),
            'matches' => ['vendor.products.*'],
        ],
        [
            'key' => 'wallet',
            'label' => 'Wallet',
            'href' => route('vendor.withdrawals.index'),
            'matches' => ['vendor.withdrawals.*'],
        ],
        [
            'key' => 'analytics',
            'label' => 'Analytics',
            'href' => route('vendor.analytics.index'),
            'matches' => ['vendor.analytics.*'],
        ],
        [
            'key' => 'affiliates',
            'label' => 'Affiliates',
            'href' => route('vendor.affiliates.index'),
            'matches' => ['vendor.affiliates.*'],
        ],
        [
            'key' => 'marketplace',
            'label' => 'Marketplace',
            'href' => route('vendor.marketplace.index'),
            'matches' => ['vendor.marketplace.*'],
        ],
        [
            'key' => 'reseller',
            'label' => 'My Reseller Products',
            'href' => route('vendor.reseller.index'),
            'matches' => ['vendor.reseller.*'],
        ],
        [
            'key' => 'afa',
            'label' => 'AFA Registration',
            'href' => route('vendor.afa.index'),
            'matches' => ['vendor.afa.*'],
        ],
        [
            'key' => 'settings',
            'label' => 'Settings',
            'href' => route('vendor.settings.index'),
            'matches' => ['vendor.settings.*'],
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
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <div class="w-8 h-8 bg-white rounded-full flex items-center justify-center">
                            <span class="text-brand-deep-blue font-medium text-sm">{{ strtoupper(substr(Auth::guard('vendor')->user()->name ?? 'VD', 0, 2)) }}</span>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium">{{ Auth::guard('vendor')->user()->name ?? 'Vendor User' }}</p>
                            <p class="text-xs text-blue-100">{{ Auth::guard('vendor')->user()->email ?? 'vendor@example.com' }}</p>
                        </div>
                    </div>
                </div>
                <!-- Mobile Logout Button -->
                <form method="POST" action="{{ route('vendor.logout') }}" class="mt-4">
                    @csrf
                    <button type="submit" class="w-full flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium text-white bg-red-500/80 hover:bg-red-500 rounded-lg transition-colors">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                        </svg>
                        Log Out
                    </button>
                </form>
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
                            <span class="text-brand-deep-blue font-medium text-sm">{{ strtoupper(substr(Auth::guard('vendor')->user()->name ?? 'VD', 0, 2)) }}</span>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-white">{{ Auth::guard('vendor')->user()->name ?? 'Vendor User' }}</p>
                            <p class="text-xs text-blue-200">{{ Auth::guard('vendor')->user()->email ?? 'vendor@example.com' }}</p>
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
                <div class="flex items-center gap-2 sm:gap-3">
                            <!-- Notification Bell -->
                            <div x-data="notificationBell()" class="relative">
                                <button @click="toggleDropdown()" 
                                        class="relative p-2 rounded-full text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-brand-deep-blue transition-colors"
                                        aria-label="View notifications">
                                    <svg class="h-5 w-5 sm:h-6 sm:w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                                    </svg>
                                    <!-- Notification Badge -->
                                    <span x-show="unreadCount > 0" 
                                          x-text="unreadCount > 99 ? '99+' : unreadCount"
                                          x-cloak
                                          class="absolute -top-1 -right-1 inline-flex items-center justify-center px-1 py-0.5 text-[10px] sm:text-xs font-bold leading-none text-white bg-red-500 rounded-full min-w-[16px] sm:min-w-[18px]">
                                    </span>
                                </button>

                                <!-- Notification Dropdown -->
                                <div x-show="isOpen" 
                                     x-cloak
                                     @click.outside="isOpen = false"
                                     x-transition:enter="transition ease-out duration-200"
                                     x-transition:enter-start="opacity-0 scale-95"
                                     x-transition:enter-end="opacity-100 scale-100"
                                     x-transition:leave="transition ease-in duration-150"
                                     x-transition:leave-start="opacity-100 scale-100"
                                     x-transition:leave-end="opacity-0 scale-95"
                                     class="fixed sm:absolute inset-x-2 sm:inset-x-auto sm:right-0 top-16 sm:top-auto sm:mt-2 w-auto sm:w-80 bg-white rounded-lg shadow-lg ring-1 ring-black ring-opacity-5 z-50 overflow-hidden max-h-[80vh] sm:max-h-none">
                                    <!-- Header -->
                                    <div class="px-4 py-3 bg-gray-50 border-b border-gray-100 flex items-center justify-between sticky top-0">
                                        <h3 class="text-sm font-semibold text-gray-900">Notifications</h3>
                                        <button x-show="unreadCount > 0" 
                                                @click="markAllRead()" 
                                                class="text-xs text-brand-deep-blue hover:text-brand-deep-blue/80 font-medium">
                                            Mark all as read
                                        </button>
                                    </div>
                                    
                                    <!-- Notifications List -->
                                    <div class="max-h-[60vh] sm:max-h-96 overflow-y-auto">
                                        <template x-if="loading">
                                            <div class="px-4 py-8 text-center text-gray-500">
                                                <svg class="animate-spin h-6 w-6 mx-auto text-brand-deep-blue" fill="none" viewBox="0 0 24 24">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                </svg>
                                                <p class="mt-2 text-sm">Loading notifications...</p>
                                            </div>
                                        </template>

                                        <template x-if="!loading && notifications.length === 0">
                                            <div class="px-4 py-8 text-center text-gray-500">
                                                <svg class="h-10 w-10 sm:h-12 sm:w-12 mx-auto text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                                                </svg>
                                                <p class="mt-2 text-sm">No notifications yet</p>
                                            </div>
                                        </template>

                                        <template x-for="notification in notifications" :key="notification.id">
                                            <div @click="markAsRead(notification.id)" 
                                                 :class="{ 'bg-blue-50': !notification.read_at }"
                                                 class="px-3 sm:px-4 py-3 border-b border-gray-100 hover:bg-gray-50 cursor-pointer transition-colors active:bg-gray-100">
                                                <div class="flex items-start gap-2 sm:gap-3">
                                                    <div :class="getIconBgClass(notification.type)" class="flex-shrink-0 w-7 h-7 sm:w-8 sm:h-8 rounded-full flex items-center justify-center">
                                                        <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path x-show="notification.type === 'new_order' || notification.type === 'affiliate_order'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                                                            <path x-show="notification.type === 'order_completed'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                            <path x-show="notification.type === 'withdrawal_approved'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V4m0 12v4" />
                                                            <path x-show="!['new_order', 'affiliate_order', 'order_completed', 'withdrawal_approved'].includes(notification.type)" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                        </svg>
                                                    </div>
                                                    <div class="flex-1 min-w-0">
                                                        <p class="text-sm font-medium text-gray-900 leading-tight" x-text="notification.title"></p>
                                                        <p class="text-xs text-gray-500 mt-0.5 line-clamp-2 leading-relaxed" x-text="notification.message"></p>
                                                        <p class="text-[11px] sm:text-xs text-gray-400 mt-1" x-text="formatTime(notification.created_at)"></p>
                                                    </div>
                                                    <span x-show="!notification.read_at" class="flex-shrink-0 w-2 h-2 bg-blue-500 rounded-full mt-1"></span>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>

                            @isset($actions)
                                <div class="flex items-center gap-3">
                                    {{ $actions }}
                                </div>
                            @endisset

                            @if ($storefrontUrl)
                                <x-button href="{{ $storefrontUrl }}" variant="outline" size="sm" class="hidden sm:inline-flex whitespace-nowrap">
                                    View Storefront
                                </x-button>
                            @endif

                            <form method="POST" action="{{ route('vendor.logout') }}" class="m-0 inline-flex">
                                @csrf
                                <x-button type="submit" variant="secondary" size="sm" class="whitespace-nowrap text-xs sm:text-sm px-2 sm:px-3">
                                    <span class="hidden sm:inline">Log Out</span>
                                    <svg class="sm:hidden h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                                    </svg>
                                </x-button>
                            </form>
                        </div>
            </div>
        </header>

        <main class="flex-1 overflow-y-auto bg-gray-50">
            <div class="py-6 px-4 sm:px-6 lg:px-8">
                {{ $slot }}
            </div>
        </main>
    </div>
</div>

<script>
    function notificationBell() {
        return {
            isOpen: false,
            loading: false,
            notifications: [],
            unreadCount: 0,

            init() {
                this.fetchNotifications();
                // Refresh notifications every 30 seconds
                setInterval(() => {
                    if (!this.isOpen) {
                        this.fetchNotifications();
                    }
                }, 30000);
            },

            async toggleDropdown() {
                this.isOpen = !this.isOpen;
                if (this.isOpen) {
                    await this.fetchNotifications();
                }
            },

            async fetchNotifications() {
                this.loading = true;
                try {
                    const response = await fetch('{{ route("vendor.notifications.index") }}');
                    const data = await response.json();
                    this.notifications = data.notifications;
                    this.unreadCount = data.unreadCount;
                } catch (error) {
                    console.error('Failed to fetch notifications:', error);
                } finally {
                    this.loading = false;
                }
            },

            async markAsRead(id) {
                try {
                    await fetch(`/vendor/notifications/${id}/read`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                        }
                    });
                    
                    const notification = this.notifications.find(n => n.id === id);
                    if (notification && !notification.read_at) {
                        notification.read_at = new Date().toISOString();
                        this.unreadCount = Math.max(0, this.unreadCount - 1);
                    }
                } catch (error) {
                    console.error('Failed to mark notification as read:', error);
                }
            },

            async markAllRead() {
                try {
                    await fetch('{{ route("vendor.notifications.read-all") }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                        }
                    });
                    
                    this.notifications.forEach(n => {
                        if (!n.read_at) {
                            n.read_at = new Date().toISOString();
                        }
                    });
                    this.unreadCount = 0;
                } catch (error) {
                    console.error('Failed to mark all notifications as read:', error);
                }
            },

            getIconBgClass(type) {
                const classes = {
                    'new_order': 'bg-green-500',
                    'affiliate_order': 'bg-purple-500',
                    'order_completed': 'bg-blue-500',
                    'withdrawal_approved': 'bg-yellow-500',
                    'withdrawal_rejected': 'bg-red-500'
                };
                return classes[type] || 'bg-gray-500';
            },

            formatTime(timestamp) {
                const date = new Date(timestamp);
                const now = new Date();
                const diff = Math.floor((now - date) / 1000);
                
                if (diff < 60) return 'Just now';
                if (diff < 3600) return Math.floor(diff / 60) + ' min ago';
                if (diff < 86400) return Math.floor(diff / 3600) + ' hours ago';
                if (diff < 604800) return Math.floor(diff / 86400) + ' days ago';
                
                return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            }
        }
    }
</script>
