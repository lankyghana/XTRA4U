<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <title>@yield('title', 'XTRA4U - Digital Services Platform')</title>
    <meta name="description" content="@yield('description', 'XTRA4U - Your trusted platform for digital services, vendor management, and secure transactions.')">
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
    
    <!-- Vite Assets -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    
    @stack('head-scripts')
    
    @stack('styles')
</head>
<body class="h-full bg-gray-50 antialiased" x-data="{ mobileMenuOpen: false }">
    <!-- Mobile Menu Overlay -->
    <div x-show="mobileMenuOpen" 
         x-transition:enter="transition-opacity ease-linear duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition-opacity ease-linear duration-300"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-50 bg-gray-900/80 lg:hidden"
         @click="mobileMenuOpen = false"></div>

    <!-- Header/Navigation -->
    @include('components.navigation')
    
    <!-- Main Content -->
    <main class="min-h-screen">
        <!-- Alert Messages -->
        @if(session('success'))
            @include('components.alert', ['type' => 'success', 'message' => session('success')])
        @endif
        
        @if(session('error'))
            @include('components.alert', ['type' => 'error', 'message' => session('error')])
        @endif
        
        @if($errors->any())
            @include('components.alert', ['type' => 'error', 'message' => 'Please fix the errors below.'])
        @endif
        
        @yield('content')
    </main>
    
    <!-- Footer -->
    @include('components.footer')
    
    @stack('scripts')
</body>
</html>
