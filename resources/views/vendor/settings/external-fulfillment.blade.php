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

                <div class="space-y-3">
                    <p class="block text-sm font-medium text-gray-700">Enabled Providers</p>
                    
                    <div class="flex items-center">
                        <input type="checkbox" name="external_fulfillment_datafyhub_enabled" id="external_fulfillment_datafyhub_enabled" value="1"
                            class="h-4 w-4 text-brand-deep-blue border-gray-300 rounded provider-toggle"
                            {{ (($settings['external_fulfillment_datafyhub_enabled'] ?? '0') === '1') ? 'checked' : '' }}>
                        <label for="external_fulfillment_datafyhub_enabled" class="ml-2 text-sm font-medium text-gray-700">
                            Datafyhub
                        </label>
                    </div>

                    <div class="flex items-center">
                        <input type="checkbox" name="external_fulfillment_xpresportal_enabled" id="external_fulfillment_xpresportal_enabled" value="1"
                            class="h-4 w-4 text-brand-deep-blue border-gray-300 rounded provider-toggle"
                            {{ (($settings['external_fulfillment_xpresportal_enabled'] ?? '0') === '1') ? 'checked' : '' }}>
                        <label for="external_fulfillment_xpresportal_enabled" class="ml-2 text-sm font-medium text-gray-700">
                            XpresPortal
                        </label>
                    </div>

                    <div class="flex items-center">
                        <input type="checkbox" name="external_fulfillment_gigshub_enabled" id="external_fulfillment_gigshub_enabled" value="1"
                            class="h-4 w-4 text-brand-deep-blue border-gray-300 rounded provider-toggle"
                            {{ (($settings['external_fulfillment_gigshub_enabled'] ?? '0') === '1') ? 'checked' : '' }}>
                        <label for="external_fulfillment_gigshub_enabled" class="ml-2 text-sm font-medium text-gray-700">
                            GigsHub
                        </label>
                    </div>

                    <div class="flex items-center">
                        <input type="checkbox" name="external_fulfillment_skdataplug_enabled" id="external_fulfillment_skdataplug_enabled" value="1"
                            class="h-4 w-4 text-brand-deep-blue border-gray-300 rounded provider-toggle"
                            {{ (($settings['external_fulfillment_skdataplug_enabled'] ?? '0') === '1') ? 'checked' : '' }}>
                        <label for="external_fulfillment_skdataplug_enabled" class="ml-2 text-sm font-medium text-gray-700">
                            SKDataPlug
                        </label>
                    </div>
                </div>

                <div id="datafyhub_token_block">
                    <label for="external_fulfillment_token" class="block text-sm font-medium text-gray-700 mb-2">
                        Datafyhub API Token
                    </label>
                    <input type="password" name="external_fulfillment_token" id="external_fulfillment_token"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-deep-blue focus:border-brand-deep-blue"
                        placeholder="{{ $settings['external_fulfillment_token_masked'] ?? '' }}">
                    @error('external_fulfillment_token')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                    <p class="mt-1 text-xs text-gray-500">Leave blank to keep your existing token.</p>
                </div>

                <div id="xpres_credentials_block" class="space-y-4">
                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                        <p class="text-sm font-semibold text-gray-800">XpresPortal Credentials (Vendor Override)</p>
                        <p class="mt-1 text-xs text-gray-500">
                            These values are stored per vendor. Leave blank to fall back to global environment values.
                        </p>
                    </div>

                    <div>
                        <label for="external_fulfillment_xpres_base_url" class="block text-sm font-medium text-gray-700 mb-2">
                            Xpres Base URL
                        </label>
                        <input
                            type="url"
                            name="external_fulfillment_xpres_base_url"
                            id="external_fulfillment_xpres_base_url"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-deep-blue focus:border-brand-deep-blue"
                            value="{{ old('external_fulfillment_xpres_base_url', $settings['external_fulfillment.xpres.base_url'] ?? '') }}"
                            placeholder="https://www.xpresportal.app"
                        >
                        @error('external_fulfillment_xpres_base_url')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="external_fulfillment_xpres_environment" class="block text-sm font-medium text-gray-700 mb-2">
                            Xpres Environment
                        </label>
                        @php($xpresEnvironment = old('external_fulfillment_xpres_environment', $settings['external_fulfillment.xpres.environment'] ?? config('services.xpresportal.environment', 'sandbox')))
                        <select
                            name="external_fulfillment_xpres_environment"
                            id="external_fulfillment_xpres_environment"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-deep-blue focus:border-brand-deep-blue"
                        >
                            <option value="sandbox" {{ $xpresEnvironment === 'sandbox' ? 'selected' : '' }}>sandbox</option>
                            <option value="production" {{ $xpresEnvironment === 'production' ? 'selected' : '' }}>production</option>
                        </select>
                        @error('external_fulfillment_xpres_environment')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="external_fulfillment_xpres_api_key" class="block text-sm font-medium text-gray-700 mb-2">
                            Xpres API Key
                        </label>
                        <input
                            type="password"
                            name="external_fulfillment_xpres_api_key"
                            id="external_fulfillment_xpres_api_key"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-deep-blue focus:border-brand-deep-blue"
                            placeholder="{{ $settings['external_fulfillment_xpres_api_key_masked'] ?? '' }}"
                        >
                        @error('external_fulfillment_xpres_api_key')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                        <p class="mt-1 text-xs text-gray-500">Leave blank to keep your existing API key.</p>
                    </div>

                    <div>
                        <label for="external_fulfillment_xpres_api_secret" class="block text-sm font-medium text-gray-700 mb-2">
                            Xpres API Secret (Optional)
                        </label>
                        <input
                            type="password"
                            name="external_fulfillment_xpres_api_secret"
                            id="external_fulfillment_xpres_api_secret"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-deep-blue focus:border-brand-deep-blue"
                            placeholder="{{ $settings['external_fulfillment_xpres_api_secret_masked'] ?? '' }}"
                        >
                        @error('external_fulfillment_xpres_api_secret')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                        <p class="mt-1 text-xs text-gray-500">Leave blank to keep your existing API secret. Some accounts use API key-only auth.</p>
                    </div>

                    <div class="flex items-center gap-3">
                        <button
                            type="button"
                            id="test_xpres_connection"
                            data-url="{{ route('vendor.settings.external-fulfillment.test-xpres') }}"
                            class="px-4 py-2 border border-brand-deep-blue text-brand-deep-blue rounded-lg font-medium hover:bg-brand-deep-blue hover:text-white transition-colors"
                        >
                            Test Xpres Connection
                        </button>
                        <p id="test_xpres_connection_result" class="text-sm"></p>
                    </div>
                </div>

                <div id="gigshub_credentials_block" class="space-y-4">
                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                        <p class="text-sm font-semibold text-gray-800">GigsHub Credentials</p>
                        <p class="mt-1 text-xs text-gray-500">
                            Configure your GigsHub API credentials for order fulfillment.
                        </p>
                    </div>

                    <div>
                        <label for="external_fulfillment_gigshub_base_url" class="block text-sm font-medium text-gray-700 mb-2">
                            GigsHub Base URL
                        </label>
                        <input
                            type="url"
                            name="external_fulfillment_gigshub_base_url"
                            id="external_fulfillment_gigshub_base_url"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-deep-blue focus:border-brand-deep-blue"
                            value="{{ old('external_fulfillment_gigshub_base_url', $settings['external_fulfillment.gigshub.base_url'] ?? '') }}"
                            placeholder="https://gigzhub.net/api/v1"
                        >
                        @error('external_fulfillment_gigshub_base_url')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="external_fulfillment_gigshub_api_key" class="block text-sm font-medium text-gray-700 mb-2">
                            GigsHub API Key
                        </label>
                        <input
                            type="password"
                            name="external_fulfillment_gigshub_api_key"
                            id="external_fulfillment_gigshub_api_key"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-deep-blue focus:border-brand-deep-blue"
                            placeholder="{{ $settings['external_fulfillment_gigshub_api_key_masked'] ?? '' }}"
                        >
                        @error('external_fulfillment_gigshub_api_key')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                        <p class="mt-1 text-xs text-gray-500">Leave blank to keep your existing API key.</p>
                    </div>
                </div>

                <div id="skdataplug_credentials_block" class="space-y-4">
                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                        <p class="text-sm font-semibold text-gray-800">SKDataPlug Credentials</p>
                        <p class="mt-1 text-xs text-gray-500">
                            Configure your SKDataPlug API credentials for order fulfillment.
                        </p>
                    </div>

                    <div>
                        <label for="external_fulfillment_skdataplug_token" class="block text-sm font-medium text-gray-700 mb-2">
                            SKDataPlug API Token
                        </label>
                        <input
                            type="password"
                            name="external_fulfillment_skdataplug_token"
                            id="external_fulfillment_skdataplug_token"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-deep-blue focus:border-brand-deep-blue"
                            placeholder="{{ $settings['external_fulfillment_skdataplug_token_masked'] ?? '' }}"
                        >
                        @error('external_fulfillment_skdataplug_token')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                        <p class="mt-1 text-xs text-gray-500">Leave blank to keep your existing API token.</p>
                    </div>
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

@push('scripts')
<script>
(() => {
    const datafyCheckbox = document.getElementById('external_fulfillment_datafyhub_enabled');
    const xpresCheckbox = document.getElementById('external_fulfillment_xpresportal_enabled');
    const gigshubCheckbox = document.getElementById('external_fulfillment_gigshub_enabled');
    const skdataplugCheckbox = document.getElementById('external_fulfillment_skdataplug_enabled');
    const datafyBlock = document.getElementById('datafyhub_token_block');
    const xpresBlock = document.getElementById('xpres_credentials_block');
    const gigshubBlock = document.getElementById('gigshub_credentials_block');
    const skdataplugBlock = document.getElementById('skdataplug_credentials_block');
    const testButton = document.getElementById('test_xpres_connection');
    const result = document.getElementById('test_xpres_connection_result');

    const syncProviderFields = () => {
        if (datafyBlock && datafyCheckbox) {
            datafyBlock.style.display = datafyCheckbox.checked ? '' : 'none';
        }

        if (xpresBlock && xpresCheckbox) {
            xpresBlock.style.display = xpresCheckbox.checked ? '' : 'none';
        }

        if (gigshubBlock && gigshubCheckbox) {
            gigshubBlock.style.display = gigshubCheckbox.checked ? '' : 'none';
        }

        if (skdataplugBlock && skdataplugCheckbox) {
            skdataplugBlock.style.display = skdataplugCheckbox.checked ? '' : 'none';
        }
    };

    if (datafyCheckbox) datafyCheckbox.addEventListener('change', syncProviderFields);
    if (xpresCheckbox) xpresCheckbox.addEventListener('change', syncProviderFields);
    if (gigshubCheckbox) gigshubCheckbox.addEventListener('change', syncProviderFields);
    if (skdataplugCheckbox) skdataplugCheckbox.addEventListener('change', syncProviderFields);
    
    syncProviderFields();

    if (!testButton || !result) {
        return;
    }

    testButton.addEventListener('click', async () => {
        result.textContent = 'Testing connection...';
        result.className = 'text-sm text-gray-600';
        testButton.disabled = true;

        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        try {
            const response = await fetch(testButton.dataset.url, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrf,
                },
            });

            const payload = await response.json();
            if (payload.success) {
                result.textContent = payload.message || 'Connection successful.';
                result.className = 'text-sm text-green-700';
            } else {
                result.textContent = payload.message || 'Connection failed.';
                result.className = 'text-sm text-red-700';
            }
        } catch (error) {
            result.textContent = 'Connection failed.';
            result.className = 'text-sm text-red-700';
        } finally {
            testButton.disabled = false;
        }
    });
})();
</script>
@endpush
