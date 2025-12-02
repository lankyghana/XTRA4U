@extends('layouts.app')

@section('title', 'Admin Login - XTRA4U')
@section('description', 'Secure access for XTRA4U administrators')

@section('content')
<div class="min-h-screen bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-md w-full mx-auto">
        <div class="text-center mb-8">
            <div class="w-16 h-16 bg-brand-deep-blue rounded-2xl mx-auto flex items-center justify-center">
                <span class="text-white text-xl font-bold">X4U</span>
            </div>
            <h1 class="mt-4 text-2xl font-bold text-gray-900">Admin Portal</h1>
            <p class="mt-2 text-sm text-gray-600">Sign in with your administrator credentials.</p>
        </div>

        <x-card>
            <form method="POST" action="{{ route('admin.login.submit') }}" class="space-y-6">
                @csrf

                @if(session('status'))
                    <x-alert type="success">{{ session('status') }}</x-alert>
                @endif

                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700">Email address</label>
                    <input
                        id="email"
                        name="email"
                        type="email"
                        required
                        autofocus
                        value="{{ old('email') }}"
                        class="mt-1 block w-full rounded-md border border-gray-300 shadow-sm focus:border-brand-deep-blue focus:ring-brand-deep-blue"
                    >
                    @error('email')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        required
                        class="mt-1 block w-full rounded-md border border-gray-300 shadow-sm focus:border-brand-deep-blue focus:ring-brand-deep-blue"
                    >
                    @error('password')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex items-center justify-between">
                    <label class="flex items-center">
                        <input type="checkbox" name="remember" class="h-4 w-4 text-brand-deep-blue border-gray-300 rounded">
                        <span class="ml-2 text-sm text-gray-600">Remember me</span>
                    </label>
                    <span class="text-sm text-gray-500">Need access? Contact support.</span>
                </div>

                <x-button type="submit" variant="primary" class="w-full justify-center">
                    Sign In
                </x-button>
            </form>
        </x-card>
    </div>
</div>
@endsection
