@extends('layouts.vendor')

@section('title', 'Settings - XTRA4U Vendor Portal')

@section('content')
<x-vendor-layout :vendor="$vendor" title="Settings" subtitle="Manage your account and preferences" active="settings">
    <div class="space-y-6">
        @if(session('success'))
            <div class="bg-gradient-to-r from-green-50 to-emerald-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg shadow-md">
                <div class="flex items-center">
                    <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                    {{ session('success') }}
                </div>
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Profile Settings -->
            <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden">
                <div class="bg-gradient-to-r from-brand-deep-blue to-blue-700 px-6 py-4">
                    <h2 class="text-xl font-bold text-white flex items-center">
                        <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                        Profile Settings
                    </h2>
                    <p class="text-blue-100">Update your business information</p>
                </div>

                <form method="POST" action="{{ route('vendor.settings.update') }}" class="p-6 space-y-4">
                    @csrf
                    @method('PUT')

                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Business Name</label>
                        <input 
                            type="text" 
                            name="name" 
                            id="name"
                            value="{{ old('name', $vendor->name) }}"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-deep-blue focus:border-brand-deep-blue"
                            required
                        >
                        @error('name')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                        <input 
                            type="email" 
                            value="{{ $vendor->email }}"
                            class="w-full px-4 py-2 border border-gray-200 rounded-lg bg-gray-50 text-gray-500"
                            disabled
                        >
                        <p class="mt-1 text-xs text-gray-500">Email cannot be changed. Contact support if needed.</p>
                    </div>

                    <div>
                        <label for="phone_number" class="block text-sm font-medium text-gray-700 mb-1">Phone Number</label>
                        <input 
                            type="text" 
                            name="phone_number" 
                            id="phone_number"
                            value="{{ old('phone_number', $vendor->phone_number) }}"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-deep-blue focus:border-brand-deep-blue"
                            required
                        >
                        @error('phone_number')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="vendor_code" class="block text-sm font-medium text-gray-700 mb-1">Vendor Code</label>
                        <div class="flex items-center">
                            <input 
                                type="text" 
                                value="{{ $vendor->vendor_code }}"
                                class="flex-1 px-4 py-2 border border-gray-200 rounded-lg bg-gray-50 text-gray-700 font-mono"
                                disabled
                            >
                            <button 
                                type="button"
                                onclick="copyToClipboard('{{ $vendor->vendor_code }}')"
                                class="ml-2 px-3 py-2 bg-gray-100 text-gray-600 rounded-lg hover:bg-gray-200 transition-colors"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/>
                                </svg>
                            </button>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">Your unique store identifier</p>
                    </div>

                    <div class="pt-4">
                        <button type="submit" class="w-full px-6 py-3 bg-brand-deep-blue text-white font-medium rounded-lg hover:bg-blue-700 transition-colors">
                            Save Profile
                        </button>
                    </div>
                </form>
            </div>

            <!-- Payout Settings -->
            <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden">
                <div class="bg-gradient-to-r from-green-500 to-green-600 px-6 py-4">
                    <h2 class="text-xl font-bold text-white flex items-center">
                        <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                        Payout Settings
                    </h2>
                    <p class="text-green-100">Configure your withdrawal details</p>
                </div>

                <form method="POST" action="{{ route('vendor.settings.update') }}" class="p-6 space-y-4">
                    @csrf
                    @method('PUT')

                    <input type="hidden" name="name" value="{{ $vendor->name }}">
                    <input type="hidden" name="phone_number" value="{{ $vendor->phone_number }}">

                    <div>
                        <label for="momo_provider" class="block text-sm font-medium text-gray-700 mb-1">Mobile Money Provider</label>
                        <select 
                            name="momo_provider" 
                            id="momo_provider"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                        >
                            <option value="">Select Provider</option>
                            @foreach (config('momo.providers', []) as $providerValue => $providerLabel)
                                <option value="{{ $providerValue }}" {{ $vendor->momo_provider === $providerValue ? 'selected' : '' }}>{{ $providerLabel }}</option>
                            @endforeach
                        </select>
                        @error('momo_provider')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="momo_number" class="block text-sm font-medium text-gray-700 mb-1">Mobile Money Number</label>
                        <input 
                            type="text" 
                            name="momo_number" 
                            id="momo_number"
                            value="{{ old('momo_number', $vendor->momo_number) }}"
                            placeholder="0XXXXXXXXX"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                        >
                        @error('momo_number')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                        <div class="flex">
                            <svg class="w-5 h-5 text-yellow-500 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                            </svg>
                            <p class="text-sm text-yellow-700">
                                Ensure your MoMo number is correct. Withdrawals are sent to this number.
                            </p>
                        </div>
                    </div>

                    <div class="pt-4">
                        <button type="submit" class="w-full px-6 py-3 bg-green-600 text-white font-medium rounded-lg hover:bg-green-700 transition-colors">
                            Save Payout Settings
                        </button>
                    </div>
                </form>
            </div>

            <!-- Change Password -->
            <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden">
                <div class="bg-gradient-to-r from-gray-600 to-gray-700 px-6 py-4">
                    <h2 class="text-xl font-bold text-white flex items-center">
                        <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                        </svg>
                        Change Password
                    </h2>
                    <p class="text-gray-300">Update your account password</p>
                </div>

                <form method="POST" action="{{ route('vendor.settings.password') }}" class="p-6 space-y-4">
                    @csrf
                    @method('PUT')

                    <div>
                        <label for="current_password" class="block text-sm font-medium text-gray-700 mb-1">Current Password</label>
                        <input 
                            type="password" 
                            name="current_password" 
                            id="current_password"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-gray-500"
                            required
                        >
                        @error('current_password')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
                        <input 
                            type="password" 
                            name="password" 
                            id="password"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-gray-500"
                            required
                            minlength="8"
                        >
                        @error('password')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="password_confirmation" class="block text-sm font-medium text-gray-700 mb-1">Confirm New Password</label>
                        <input 
                            type="password" 
                            name="password_confirmation" 
                            id="password_confirmation"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-gray-500"
                            required
                            minlength="8"
                        >
                    </div>

                    <div class="pt-4">
                        <button type="submit" class="w-full px-6 py-3 bg-gray-700 text-white font-medium rounded-lg hover:bg-gray-800 transition-colors">
                            Update Password
                        </button>
                    </div>
                </form>
            </div>

            <!-- Store Link -->
            <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden">
                <div class="bg-gradient-to-r from-purple-500 to-purple-600 px-6 py-4">
                    <h2 class="text-xl font-bold text-white flex items-center">
                        <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                        </svg>
                        Your Store Link
                    </h2>
                    <p class="text-purple-100">Share this link with your customers</p>
                </div>

                <div class="p-6">
                    <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                        <code class="text-sm text-gray-700 break-all" id="store-link">
                            {{ route('storefront.vendor', $vendor->vendor_code) }}
                        </code>
                    </div>

                    <div class="mt-4 flex gap-3">
                        <button 
                            onclick="copyToClipboard('{{ route('storefront.vendor', $vendor->vendor_code) }}')"
                            class="flex-1 px-4 py-2 bg-purple-600 text-white font-medium rounded-lg hover:bg-purple-700 transition-colors flex items-center justify-center"
                        >
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/>
                            </svg>
                            Copy Link
                        </button>
                        <a 
                            href="{{ route('storefront.vendor', $vendor->vendor_code) }}"
                            target="_blank"
                            class="px-4 py-2 bg-gray-100 text-gray-700 font-medium rounded-lg hover:bg-gray-200 transition-colors flex items-center"
                        >
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                            </svg>
                            Visit Store
                        </a>
                    </div>

                    <div class="mt-4 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                        <p class="text-sm text-blue-700">
                            <strong>Tip:</strong> Share your store link on social media, WhatsApp, or anywhere to let customers find and purchase from your store directly.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-vendor-layout>

@push('scripts')
<script>
    function copyToClipboard(text) {
        navigator.clipboard.writeText(text).then(function() {
            alert('Copied to clipboard!');
        });
    }
</script>
@endpush
@endsection
