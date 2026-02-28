@extends('layouts.vendor')

@section('title', 'External Fulfillment Settings - XTRA4U Vendor Portal')

@section('content')
<x-vendor-layout :vendor="$vendor" title="External Fulfillment" subtitle="Configure your external fulfillment integration" active="settings">
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

        <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden">
            <div class="bg-gradient-to-r from-brand-deep-blue to-blue-700 px-6 py-4">
                <h2 class="text-xl font-bold text-white">External Fulfillment Settings</h2>
                <p class="text-blue-100">Enable and configure your API credentials</p>
            </div>

            <form method="POST" action="{{ route('vendor.settings.external-fulfillment.update') }}" class="p-6 space-y-6">
                @csrf
                @method('PUT')

                <div class="flex items-center">
                    <input type="checkbox" name="external_fulfillment_enabled" id="external_fulfillment_enabled" value="1"
                        class="h-4 w-4 text-brand-deep-blue border-gray-300 rounded"
                        {{ (($settings['external_fulfillment_enabled'] ?? '0') === '1') ? 'checked' : '' }}>
                    <label for="external_fulfillment_enabled" class="ml-2 text-sm font-medium text-gray-700">
                        Enable external fulfillment
                    </label>
                </div>

                <div>
                    <label for="external_fulfillment_provider" class="block text-sm font-medium text-gray-700 mb-2">
                        Provider
                    </label>
                    <select name="external_fulfillment_provider" id="external_fulfillment_provider"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-deep-blue focus:border-brand-deep-blue">
                        @foreach($providers as $key => $label)
                            <option value="{{ $key }}" {{ (($settings['external_fulfillment_provider'] ?? 'datafyhub') === $key) ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('external_fulfillment_provider')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="external_fulfillment_token" class="block text-sm font-medium text-gray-700 mb-2">
                        API Token
                    </label>
                    <input type="password" name="external_fulfillment_token" id="external_fulfillment_token"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-deep-blue focus:border-brand-deep-blue"
                        placeholder="{{ $settings['external_fulfillment_token_masked'] ?? '' }}">
                    @error('external_fulfillment_token')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                    <p class="mt-1 text-xs text-gray-500">Leave blank to keep your existing token.</p>
                </div>

                <div>
                    <label for="external_fulfillment_timeout_seconds" class="block text-sm font-medium text-gray-700 mb-2">
                        Timeout (seconds)
                    </label>
                    <input type="number" name="external_fulfillment_timeout_seconds" id="external_fulfillment_timeout_seconds"
                        min="1" max="120"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-deep-blue focus:border-brand-deep-blue"
                        value="{{ $settings['external_fulfillment_timeout_seconds'] ?? '10' }}">
                    @error('external_fulfillment_timeout_seconds')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="pt-2">
                    <button type="submit" class="w-full px-6 py-3 bg-brand-deep-blue text-white font-medium rounded-lg hover:bg-blue-700 transition-colors">
                        Save Settings
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-vendor-layout>
@endsection
