@extends('layouts.admin')

@section('title', 'USSD Settings - XTRA4U Admin')
@section('description', 'Configure the USSD channel for customer self-service')

@section('content')
<x-admin-layout title="USSD Settings" subtitle="Configure the USSD channel and Moolre integration" active="ussd-settings">
    <x-slot name="actions">
        <span class="hidden sm:inline text-sm {{ ($settings['ussd_enabled'] ?? '1') === '1' ? 'text-green-600' : 'text-gray-400' }}">
            <svg class="inline w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
            </svg>
            USSD is {{ ($settings['ussd_enabled'] ?? '1') === '1' ? 'active' : 'disabled' }}
        </span>
    </x-slot>

    <div class="max-w-4xl">

        {{-- Flash messages --}}
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

        {{-- Setup guide --}}
        <div class="mb-8 bg-gradient-to-r from-brand-deep-blue to-brand-bright-blue rounded-xl p-6 text-white">
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <svg class="h-8 w-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <h3 class="text-lg font-semibold">Moolre USSD Setup</h3>
                    <p class="mt-1 text-blue-100 text-sm">To activate the USSD channel, give Moolre the callback URL below and ask them to:</p>
                    <ul class="mt-2 text-sm text-blue-100 space-y-1">
                        <li>• Assign you a USSD service code (e.g. <strong>*714*X#</strong>)</li>
                        <li>• Set the <strong>callback URL</strong> to the one shown in the Endpoint Info card</li>
                        <li>• Configure the method as <strong>POST</strong></li>
                    </ul>
                    <p class="mt-3 text-sm text-blue-100">
                        Customers dial <strong>*SERVICE_CODE*VENDOR_CODE#</strong> to route to a specific vendor,
                        or just <strong>*SERVICE_CODE#</strong> to reach the default vendor below.
                    </p>
                </div>
            </div>
        </div>

        {{-- Configuration form --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                    <svg class="w-5 h-5 mr-2 text-brand-deep-blue" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                    Configuration
                </h2>
            </div>

            <form action="{{ route('admin.settings.ussd.update') }}" method="POST" class="p-6 space-y-6">
                @csrf
                @method('PUT')

                {{-- Enable toggle --}}
                <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg border border-gray-200">
                    <div>
                        <p class="text-sm font-medium text-gray-900">Enable USSD Channel</p>
                        <p class="text-xs text-gray-500 mt-0.5">When disabled, all USSD requests return an out-of-service message immediately.</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer ml-4 flex-shrink-0">
                        <input type="checkbox" name="ussd_enabled" value="1" class="sr-only peer"
                               {{ ($settings['ussd_enabled'] ?? '1') === '1' ? 'checked' : '' }}>
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-brand-deep-blue/20 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-brand-deep-blue"></div>
                    </label>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                    {{-- Service code --}}
                    <div>
                        <label for="ussd_service_code" class="block text-sm font-medium text-gray-700 mb-2">
                            USSD Service Code
                        </label>
                        <input type="text" name="ussd_service_code" id="ussd_service_code"
                               value="{{ $settings['ussd_service_code'] ?? '' }}"
                               class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-brand-deep-blue focus:border-brand-deep-blue"
                               placeholder="e.g. *714*5#">
                        <p class="mt-1 text-xs text-gray-500">Assigned by Moolre — for display and reference only.</p>
                    </div>

                    {{-- Support number --}}
                    <div>
                        <label for="ussd_support_number" class="block text-sm font-medium text-gray-700 mb-2">
                            Support Number
                        </label>
                        <input type="text" name="ussd_support_number" id="ussd_support_number"
                               value="{{ $settings['ussd_support_number'] ?? '' }}"
                               class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-brand-deep-blue focus:border-brand-deep-blue"
                               placeholder="e.g. 0531192005">
                        <p class="mt-1 text-xs text-gray-500">Shown at the bottom of every USSD menu screen.</p>
                    </div>

                    {{-- Welcome message --}}
                    <div class="md:col-span-2">
                        <label for="ussd_welcome_message" class="block text-sm font-medium text-gray-700 mb-2">
                            Welcome Message <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="ussd_welcome_message" id="ussd_welcome_message"
                               value="{{ $settings['ussd_welcome_message'] ?? 'Welcome to XTRA4U' }}"
                               maxlength="100"
                               class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-brand-deep-blue focus:border-brand-deep-blue"
                               required>
                        <p class="mt-1 text-xs text-gray-500">Greeting shown on the main menu. Max 100 characters.</p>
                    </div>

                    {{-- Default vendor --}}
                    <div class="md:col-span-2">
                        <label for="ussd_default_vendor_id" class="block text-sm font-medium text-gray-700 mb-2">
                            Default Vendor
                        </label>
                        <select name="ussd_default_vendor_id" id="ussd_default_vendor_id"
                                class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-brand-deep-blue focus:border-brand-deep-blue">
                            <option value="">— First approved vendor (automatic) —</option>
                            @foreach ($vendors as $vendor)
                                <option value="{{ $vendor->id }}"
                                    {{ ($settings['ussd_default_vendor_id'] ?? '') == $vendor->id ? 'selected' : '' }}>
                                    {{ $vendor->business_name }} ({{ $vendor->vendor_code }})
                                </option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-gray-500">
                            Used when a customer dials without a vendor extension. If left on automatic, the first approved vendor is used.
                        </p>
                    </div>

                </div>

                <div class="pt-4 border-t border-gray-200 flex justify-end">
                    <button type="submit"
                            class="inline-flex items-center px-6 py-3 bg-brand-deep-blue text-white font-semibold rounded-lg shadow-sm hover:bg-brand-bright-blue focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-brand-deep-blue transition-colors">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        Save Settings
                    </button>
                </div>
            </form>
        </div>

        {{-- Endpoint info card --}}
        <div class="mt-8 bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                    <svg class="w-5 h-5 mr-2 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                    </svg>
                    Endpoint Info
                </h2>
            </div>
            <div class="p-6 space-y-4">
                <p class="text-sm text-gray-600">Give these details to Moolre when registering your USSD service.</p>

                <div>
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Callback URL</p>
                    <div class="flex items-center gap-2">
                        <code id="ussd-callback-url"
                              class="flex-1 block bg-gray-100 text-gray-800 text-sm rounded-lg px-4 py-2 font-mono break-all">{{ route('api.ussd') }}</code>
                        <button type="button" onclick="copyCallbackUrl()"
                                class="flex-shrink-0 inline-flex items-center px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 bg-white hover:bg-gray-50 transition-colors">
                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                            </svg>
                            Copy
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2">
                    <div>
                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Method</p>
                        <code class="block bg-gray-100 text-gray-800 text-sm rounded-lg px-4 py-2 font-mono">POST</code>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Content-Type</p>
                        <code class="block bg-gray-100 text-gray-800 text-sm rounded-lg px-4 py-2 font-mono">application/x-www-form-urlencoded</code>
                    </div>
                </div>

                <div class="pt-2">
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">Expected Request Fields</p>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="bg-gray-50 border-b border-gray-200">
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Field</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr><td class="px-4 py-2 font-mono text-indigo-600">sessionId</td><td class="px-4 py-2 text-gray-600">Unique session identifier</td></tr>
                                <tr><td class="px-4 py-2 font-mono text-indigo-600">phoneNumber</td><td class="px-4 py-2 text-gray-600">Customer's phone number</td></tr>
                                <tr><td class="px-4 py-2 font-mono text-indigo-600">network</td><td class="px-4 py-2 text-gray-600">Mobile network (MTN, Telecel, AT)</td></tr>
                                <tr><td class="px-4 py-2 font-mono text-indigo-600">serviceCode</td><td class="px-4 py-2 text-gray-600">The USSD service code dialled</td></tr>
                                <tr><td class="px-4 py-2 font-mono text-indigo-600">text</td><td class="px-4 py-2 text-gray-600">Concatenated user input (e.g. <code class="bg-gray-100 px-1 rounded">1*2*0244123456</code>)</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>
</x-admin-layout>
@endsection

@push('scripts')
<script>
function copyCallbackUrl() {
    const url = document.getElementById('ussd-callback-url').textContent.trim();
    navigator.clipboard.writeText(url).then(() => {
        const btn = event.currentTarget;
        const original = btn.innerHTML;
        btn.innerHTML = '<svg class="w-4 h-4 mr-1 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>Copied!';
        btn.classList.add('text-green-600', 'border-green-300');
        setTimeout(() => { btn.innerHTML = original; btn.classList.remove('text-green-600', 'border-green-300'); }, 2000);
    });
}
</script>
@endpush
