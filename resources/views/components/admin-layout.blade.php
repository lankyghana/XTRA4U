@props([
    'title' => 'Admin Portal',
    'subtitle' => null,
    'active' => null,
])

@once
    @php
        if (! function_exists('admin_nav_paths')) {
            function admin_nav_paths(string $name): string
            {
                return match ($name) {
                    'dashboard' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2-2z" />'
                        . '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5a2 2 0 012-2h4a2 2 0 012 2v6h-8V5z" />',
                    'vendors' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />',
                    'orders' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5h6m-6 0a2 2 0 00-2 2v1h10V7a2 2 0 00-2-2M9 5a2 2 0 00-2 2v10a2 2 0 002 2h6a2 2 0 002-2V7a2 2 0 00-2-2m-6 7h6m-6 4h6" />',
                    'transactions' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />',
                    'withdrawals' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V4m0 12v4m8-10a8 8 0 11-16 0 8 8 0 0116 0z" />',
                    'network-services' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v2m0 14v2m9-9h-2M5 12H3m15.364-6.364l-1.414 1.414M7.05 16.95l-1.414 1.414M16.95 16.95l1.414 1.414M7.05 7.05L5.636 5.636M12 7a5 5 0 110 10 5 5 0 010-10z" />',
                    'users' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z" />',
                    'reports' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />',
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
            'href' => route('admin.dashboard'),
            'matches' => ['admin.dashboard'],
        ],
        [
            'key' => 'vendors',
            'label' => 'Vendors',
            'href' => route('admin.vendors.index'),
            'matches' => ['admin.vendors.*'],
        ],
        [
            'key' => 'network-services',
            'label' => 'Networks',
            'href' => route('admin.network-services.index'),
            'matches' => ['admin.network-services.*'],
        ],
        [
            'key' => 'orders',
            'label' => 'Orders',
            'href' => route('admin.orders.index'),
            'matches' => ['admin.orders.*'],
        ],
        [
            'key' => 'transactions',
            'label' => 'Transactions',
            'href' => route('admin.transactions.index'),
            'matches' => ['admin.transactions.*'],
        ],
        [
            'key' => 'withdrawals',
            'label' => 'Withdrawals',
            'href' => route('admin.withdrawals.index'),
            'matches' => ['admin.withdrawals.*'],
        ],
        [
            'key' => 'users',
            'label' => 'Users',
            'href' => '#',
            'matches' => [],
        ],
        [
            'key' => 'reports',
            'label' => 'Reports',
            'href' => '#',
            'matches' => [],
        ],
        [
            'key' => 'settings',
            'label' => 'Settings',
              'href' => route('admin.paystack-config.form'),
              'matches' => ['admin.paystack-config.form', 'admin.paystack-config.update'],
        ],
    ];

    foreach ($navLinks as &$link) {
        $link['isActive'] = (($active ?? null) === $link['key'])
            || (!empty($link['matches']) && request()->routeIs(...$link['matches']));
    }
    unset($link);

    $linkBaseClasses = 'group flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors';
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    
    <title>{{ $title }} - Admin Portal - XTRA4U</title>
    <meta name="description" content="System administration portal for XTRA4U">
    
    <!-- Styles & Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    
    @stack('styles')
</head>
<body class="font-sans antialiased bg-gray-50">

<div x-data="{ openSidebar: false }" @keydown.window.escape="openSidebar = false" class="flex min-h-screen bg-gray-50">
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
             class="relative flex w-4/5 max-w-sm flex-col bg-white shadow-2xl rounded-r-2xl">
            <div class="flex items-center justify-between px-4 pt-5 pb-4 border-b border-gray-100">
                <div class="flex items-center">
                    <div class="w-8 h-8 bg-brand-deep-blue rounded-lg flex items-center justify-center">
                        <span class="text-white font-bold text-sm">X4U</span>
                    </div>
                    <span class="ml-2 text-lg font-bold text-gray-900">Admin Portal</span>
                </div>
                <button type="button" class="rounded-md p-2 text-gray-400 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-brand-deep-blue" @click="openSidebar = false" aria-label="Close sidebar">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div class="flex-1 overflow-y-auto px-4 py-6 space-y-1">
                @foreach ($navLinks as $link)
                    @php
                        $linkClasses = $link['isActive']
                            ? 'bg-brand-deep-blue text-white'
                            : 'text-gray-600 hover:text-gray-900 hover:bg-gray-100';
                        $iconClasses = $link['isActive']
                            ? 'text-white'
                            : 'text-gray-400 group-hover:text-gray-500';
                    @endphp
                    <a href="{{ $link['href'] }}" class="{{ $linkBaseClasses }} {{ $linkClasses }}" @click="openSidebar = false">
                        <svg class="{{ $iconClasses }} mr-3 h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            {!! admin_nav_paths($link['key']) !!}
                        </svg>
                        {{ $link['label'] }}
                    </a>
                @endforeach
            </div>
            <div class="border-t border-gray-100 px-4 py-4">
                <div class="flex items-center">
                    <div class="w-8 h-8 bg-brand-deep-blue rounded-full flex items-center justify-center">
                        <span class="text-white font-medium text-sm">{{ strtoupper(substr(Auth::user()->name ?? 'AD', 0, 2)) }}</span>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-gray-700">{{ Auth::user()->name ?? 'Admin User' }}</p>
                        <p class="text-xs text-gray-500">{{ Auth::user()->email ?? 'admin@xtra4u.com' }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Desktop sidebar -->
    <div class="hidden md:flex md:w-64 md:flex-col bg-white text-gray-900 shadow-lg" aria-label="Admin navigation">
        <div class="flex flex-col grow pt-5 overflow-y-auto">
            <div class="flex items-center shrink-0 px-4 pb-4 border-b border-gray-200">
                <div class="w-8 h-8 bg-brand-deep-blue rounded-lg flex items-center justify-center">
                    <span class="text-white font-bold text-sm">X4U</span>
                </div>
                <span class="ml-2 text-lg font-bold text-gray-900">Admin Portal</span>
            </div>
            <div class="mt-6 grow flex flex-col">
                <nav class="flex-1 px-2 pb-4 space-y-1">
                    @foreach ($navLinks as $link)
                        @php
                            $linkClasses = $link['isActive']
                                ? 'bg-brand-deep-blue text-white'
                                : 'text-gray-600 hover:text-gray-900 hover:bg-gray-100';
                            $iconClasses = $link['isActive']
                                ? 'text-white'
                                : 'text-gray-400 group-hover:text-gray-500';
                        @endphp
                        <a href="{{ $link['href'] }}" class="{{ $linkBaseClasses }} {{ $linkClasses }}">
                            <svg class="{{ $iconClasses }} mr-3 h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                {!! admin_nav_paths($link['key']) !!}
                            </svg>
                            {{ $link['label'] }}
                        </a>
                    @endforeach
                </nav>
                <div class="shrink-0 flex border-t border-gray-200 p-4">
                    <div class="flex items-center w-full justify-between">
                        <div class="flex items-center">
                            <div class="w-8 h-8 bg-brand-deep-blue rounded-full flex items-center justify-center">
                                <span class="text-white font-medium text-sm">{{ strtoupper(substr(Auth::user()->name ?? 'AD', 0, 2)) }}</span>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm font-medium text-gray-700">{{ Auth::user()->name ?? 'Admin User' }}</p>
                                <p class="text-xs text-gray-500">{{ Auth::user()->email ?? 'admin@xtra4u.com' }}</p>
                            </div>
                        </div>
                        <form method="POST" action="{{ route('admin.logout') }}">
                            @csrf
                            <button type="submit" class="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-md transition-colors" title="Logout">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                                </svg>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main content -->
    <div class="flex flex-col flex-1 overflow-hidden">
        <header class="bg-white shadow-sm border-b border-gray-200">
            <div class="flex items-center justify-between px-4 py-3 sm:px-6 lg:px-8">
                <div class="flex items-center gap-3 min-w-0 flex-1">
                    <button class="md:hidden p-2 -ml-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-brand-deep-blue flex-shrink-0" @click="openSidebar = true" aria-label="Open sidebar">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>
                    <div class="min-w-0">
                        <h1 class="text-lg sm:text-2xl font-bold text-gray-900 truncate">{{ $title }}</h1>
                        @if ($subtitle)
                            <p class="text-xs sm:text-sm text-gray-500 truncate">{{ $subtitle }}</p>
                        @endif
                    </div>
                </div>
                <div class="flex items-center gap-2 flex-shrink-0 ml-2">
                    <!-- Admin Notification Bell -->
                    <div x-data="adminNotificationBell()" class="relative" @keydown.escape.window="isOpen = false">
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
                                  class="absolute -top-0.5 -right-0.5 inline-flex items-center justify-center px-1 py-0.5 text-[10px] font-bold leading-none text-white bg-red-500 rounded-full min-w-[16px]">
                            </span>
                        </button>

                        <!-- Notification Dropdown - Full screen on mobile -->
                        <div x-show="isOpen" 
                             x-cloak
                             @click.outside="isOpen = false"
                             x-transition:enter="transition ease-out duration-200"
                             x-transition:enter-start="opacity-0 translate-y-1"
                             x-transition:enter-end="opacity-100 translate-y-0"
                             x-transition:leave="transition ease-in duration-150"
                             x-transition:leave-start="opacity-100 translate-y-0"
                             x-transition:leave-end="opacity-0 translate-y-1"
                             class="fixed inset-x-0 top-0 bottom-0 sm:absolute sm:inset-auto sm:right-0 sm:top-full sm:mt-2 sm:w-96 sm:rounded-lg sm:max-h-[500px] bg-white shadow-2xl sm:shadow-lg ring-1 ring-black/5 z-[60] flex flex-col">
                            <!-- Header -->
                            <div class="px-4 py-3 bg-gray-50 border-b border-gray-200 flex items-center justify-between flex-shrink-0 sm:rounded-t-lg">
                                <h3 class="text-base sm:text-sm font-semibold text-gray-900">Notifications</h3>
                                <div class="flex items-center gap-3">
                                    <button x-show="unreadCount > 0" 
                                            @click="markAllRead()" 
                                            class="text-xs text-brand-deep-blue hover:text-brand-deep-blue/80 font-medium">
                                        Mark all read
                                    </button>
                                    <button @click="isOpen = false" class="sm:hidden p-1 -mr-1 rounded text-gray-400 hover:text-gray-600">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Notifications List -->
                            <div class="flex-1 overflow-y-auto overscroll-contain">
                                <template x-if="loading">
                                    <div class="px-4 py-12 text-center text-gray-500">
                                        <svg class="animate-spin h-8 w-8 mx-auto text-brand-deep-blue" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        <p class="mt-3 text-sm">Loading...</p>
                                    </div>
                                </template>

                                <template x-if="!loading && notifications.length === 0">
                                    <div class="px-4 py-12 text-center text-gray-500">
                                        <svg class="h-16 w-16 mx-auto text-gray-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                                        </svg>
                                        <p class="mt-3 text-sm font-medium">No notifications</p>
                                        <p class="mt-1 text-xs text-gray-400">You're all caught up!</p>
                                    </div>
                                </template>

                                <template x-for="notification in notifications" :key="notification.id">
                                    <div @click="markAsRead(notification.id)" 
                                         :class="{ 'bg-blue-50/70': !notification.read_at }"
                                         class="px-4 py-3 border-b border-gray-100 hover:bg-gray-50 cursor-pointer transition-colors active:bg-gray-100">
                                        <div class="flex items-start gap-3">
                                            <div :class="getIconBgClass(notification.type)" class="flex-shrink-0 w-9 h-9 rounded-full flex items-center justify-center shadow-sm">
                                                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path x-show="notification.type === 'new_order' || notification.type === 'affiliate_order'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                                                    <path x-show="notification.type === 'new_vendor'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                                                    <path x-show="notification.type === 'withdrawal_request'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V4m0 12v4" />
                                                    <path x-show="notification.type === 'order_completed'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                    <path x-show="notification.type === 'vendor_approved'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                                    <path x-show="!['new_order', 'affiliate_order', 'new_vendor', 'withdrawal_request', 'order_completed', 'vendor_approved'].includes(notification.type)" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                </svg>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-start justify-between gap-2">
                                                    <p class="text-sm font-medium text-gray-900 leading-tight" x-text="notification.title"></p>
                                                    <span x-show="!notification.read_at" class="flex-shrink-0 w-2 h-2 bg-blue-500 rounded-full mt-1.5"></span>
                                                </div>
                                                <p class="text-xs text-gray-600 mt-1 line-clamp-2 leading-relaxed" x-text="notification.message"></p>
                                                <p class="text-[11px] text-gray-400 mt-1.5" x-text="formatTime(notification.created_at)"></p>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                    
                    @isset($actions)
                        <div class="hidden sm:flex items-center gap-3">
                            {{ $actions }}
                        </div>
                    @endisset
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

@stack('scripts')

<script>
    function adminNotificationBell() {
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
                    const response = await fetch('{{ route("admin.notifications.index") }}');
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
                    await fetch(`/admin/notifications/${id}/read`, {
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
                    await fetch('{{ route("admin.notifications.read-all") }}', {
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
                    'order_cancelled': 'bg-red-500',
                    'new_vendor': 'bg-indigo-500',
                    'vendor_approved': 'bg-teal-500',
                    'vendor_rejected': 'bg-red-500',
                    'withdrawal_request': 'bg-yellow-500',
                    'withdrawal_approved': 'bg-green-600',
                    'withdrawal_rejected': 'bg-red-600',
                    'new_product': 'bg-cyan-500'
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
</body>
</html>
