<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-gray-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <title>@yield('title', 'Developers') - XTRA4U</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full text-gray-900">
@php
    $vendor = auth('vendor')->user();
    $approved = $vendor && $vendor->is_approved === true;
@endphp
<header class="bg-white border-b border-gray-200">
    <div class="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between gap-4 flex-wrap">
        <a href="{{ route('developers.landing') }}" class="font-semibold text-lg">XTRA4U <span class="text-gray-500 font-normal">Developers</span></a>
        <nav class="flex items-center gap-4 text-sm flex-wrap">
            @if($approved)
                <a href="{{ route('developers.dashboard') }}" class="hover:underline">Dashboard</a>
                <a href="{{ route('developers.applications.index') }}" class="hover:underline">Applications</a>
                <a href="{{ route('vendor.dashboard') }}" class="hover:underline">Vendor dashboard</a>
                <span class="text-gray-400">{{ $vendor->name }}</span>
                <form method="POST" action="{{ route('vendor.logout') }}">@csrf
                    <button type="submit" class="text-gray-700 hover:underline">Sign out</button>
                </form>
            @else
                <a href="{{ route('vendor.login.form') }}" class="hover:underline">Vendor sign in</a>
                <a href="{{ route('vendor.request.form') }}" class="rounded-lg bg-[#00942C] text-white px-3 py-1.5">Become a vendor</a>
            @endif
        </nav>
    </div>
</header>

<main class="max-w-6xl mx-auto px-4 py-8">
    @if(session('status'))
        <div class="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800" role="status">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
            <ul class="list-disc ml-5 space-y-1">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif
    @yield('content')
</main>
</body>
</html>
