@extends('layouts.admin')

@section('title', 'External Fulfillment Settings - XTRA4U Admin')
@section('description', 'Configure external fulfillment API settings for the platform')

@section('content')
<x-admin-layout title="External Fulfillment" subtitle="Configure the external fulfillment API (runs asynchronously after payment)" active="external-fulfillment-settings">
    <div class="max-w-4xl">
        @if (session('success'))
            <div class="mb-6 bg-green-50 border-l-4 border-green-500 rounded-lg p-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-green-400" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                        </svg>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-green-800">{{ session('success') }}</p>
                    </div>
                </div>
            </div>
        @endif

        @if (session('error'))
            <div class="mb-6 bg-red-50 border-l-4 border-red-500 rounded-lg p-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-red-400" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                        </svg>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-red-800">{{ session('error') }}</p>
                    </div>
                </div>
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-6 bg-red-50 border-l-4 border-red-500 rounded-lg p-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-red-400" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                        </svg>
                    </div>
                    <div class="ml-3">
                        <ul class="list-disc list-inside text-sm text-red-700 space-y-1">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        @endif

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                    <svg class="w-5 h-5 mr-2 text-brand-deep-blue" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                    </svg>
                    API Configuration
                </h2>
                <p class="mt-1 text-sm text-gray-600">Runs in a queued job after the order is marked paid.</p>
                <p class="mt-1 text-xs text-gray-500">Base URL and endpoint are configured by the system (not editable here).</p>
            </div>

            <form action="{{ route('admin.settings.external-fulfillment.update') }}" method="POST" class="p-6 space-y-6">
                @csrf
                @method('PUT')

                <div class="flex items-center gap-3">
                    <input type="checkbox" name="external_fulfillment_enabled" id="external_fulfillment_enabled" value="1"
                           class="h-4 w-4 text-brand-deep-blue border-gray-300 rounded focus:ring-brand-deep-blue"
                           {{ (($settings['external_fulfillment_enabled'] ?? '0') === '1') ? 'checked' : '' }}>
                    <label for="external_fulfillment_enabled" class="text-sm font-medium text-gray-700">
                        Enable External Fulfillment
                    </label>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="external_fulfillment_token" class="block text-sm font-medium text-gray-700 mb-2">
                            API Key / Token
                        </label>
                        <input type="password" name="external_fulfillment_token" id="external_fulfillment_token"
                               value=""
                               class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-brand-deep-blue focus:border-brand-deep-blue"
                               placeholder="{{ $settings['external_fulfillment_token_masked'] ?? '' }}">
                        <p class="mt-1 text-xs text-gray-500">Leave blank to keep existing token.</p>
                    </div>

                    <div>
                        <label for="external_fulfillment_timeout_seconds" class="block text-sm font-medium text-gray-700 mb-2">
                            Timeout (seconds)
                        </label>
                        <input type="number" name="external_fulfillment_timeout_seconds" id="external_fulfillment_timeout_seconds"
                               value="{{ $settings['external_fulfillment_timeout_seconds'] ?? '10' }}"
                               class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-brand-deep-blue focus:border-brand-deep-blue"
                               min="1" max="120">
                        <p class="mt-1 text-xs text-gray-500">Default: 10</p>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 pt-2">
                    <button type="submit" class="px-5 py-2.5 bg-brand-deep-blue text-white rounded-lg hover:bg-brand-bright-blue transition-colors font-medium">
                        Save Settings
                    </button>
                </div>
            </form>
        </div>

        <div class="mt-6 text-xs text-gray-500">
            Notes: This integration only updates internal tracking fields on the order (no customer/vendor UI changes).
        </div>
    </div>
</x-admin-layout>
@endsection
