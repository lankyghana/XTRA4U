@extends('layouts.admin')

@section('title', 'USSD Settings - XTRA4U Admin')
@section('description', 'Configure the USSD channel for customer self-service')

@section('content')
<x-admin-layout title="USSD Settings" subtitle="General settings plus two separate gateway integrations: Moolre and GWO SEVO" active="ussd-settings">
    <x-slot name="actions">
        <span class="hidden sm:inline text-sm {{ $status['master'] ? 'text-green-600' : 'text-gray-400' }}">
            <svg class="inline w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
            </svg>
            USSD channel is {{ $status['master'] ? 'enabled' : 'disabled' }}
        </span>
    </x-slot>

    @php
        $badge = function (string $tone, string $label): string {
            $classes = [
                'green' => 'bg-green-100 text-green-800',
                'gray' => 'bg-gray-100 text-gray-700',
                'amber' => 'bg-amber-100 text-amber-800',
                'red' => 'bg-red-100 text-red-800',
            ][$tone];

            return '<span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium '.$classes.'">'.e($label).'</span>';
        };
        $input = 'block w-full border-gray-300 rounded-lg shadow-sm focus:ring-brand-violet focus:border-brand-violet';
        $codeChip = 'bg-gray-100 px-1 rounded';
    @endphp

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

        {{-- Overview --}}
        <div class="mb-8 rounded-xl border border-violet-200 bg-violet-50 p-5 text-gray-800">
            <h3 class="text-lg font-semibold">Two separate USSD gateways</h3>
            <p class="mt-1 text-sm text-gray-600">
                <strong>Moolre</strong> and <strong>GWO SEVO</strong> are different providers. Each has its own callback URL, request format,
                authentication and settings below, and neither can serve the other's traffic. The general settings in section A are shared
                by both; there is no gateway selector or switch between them.
            </p>
        </div>

        <form action="{{ route('admin.settings.ussd.update') }}" method="POST" class="space-y-8">
            @csrf
            @method('PUT')

            {{-- ===================== SECTION A: GENERAL ===================== --}}
            <section class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden" id="ussd-general">
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                    <h2 class="text-lg font-semibold text-gray-900">A. General USSD Settings</h2>
                    <p class="text-xs text-gray-500 mt-0.5">Apply to both gateways.</p>
                </div>
                <div class="p-6 space-y-6">

                    <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg border border-gray-200">
                        <div>
                            <p class="text-sm font-medium text-gray-900">
                                Enable USSD Channel
                                <span class="ml-2 align-middle">{!! $status['master'] ? $badge('green', 'Enabled') : $badge('gray', 'Disabled') !!}</span>
                            </p>
                            <p class="text-xs text-gray-500 mt-0.5">Master switch for <strong>both</strong> gateways: when disabled, requests to the Moolre and GWO SEVO endpoints all return an out-of-service message immediately. GWO SEVO also has its own enable setting in section C.</p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer ml-4 flex-shrink-0">
                            <input type="checkbox" name="ussd_enabled" value="1" class="sr-only peer"
                                   {{ ($settings['ussd_enabled'] ?? '1') === '1' ? 'checked' : '' }}>
                            <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-brand-violet/20 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-brand-violet"></div>
                        </label>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="ussd_base_code" class="block text-sm font-medium text-gray-700 mb-2">Global USSD Base Code</label>
                            <input type="text" name="ussd_base_code" id="ussd_base_code"
                                   value="{{ $settings['ussd_base_code'] ?? '' }}"
                                   class="{{ $input }}" placeholder="e.g. *203*">
                            <p class="mt-1 text-xs text-gray-500">Prefix for every generated vendor code, e.g. <code>*203*</code> &rarr; <code>*203*45*102#</code>.</p>
                        </div>

                        <div>
                            <label for="ussd_service_code" class="block text-sm font-medium text-gray-700 mb-2">
                                USSD Service Code <span class="text-gray-400 font-normal">(legacy)</span>
                            </label>
                            <input type="text" name="ussd_service_code" id="ussd_service_code"
                                   value="{{ $settings['ussd_service_code'] ?? '' }}"
                                   class="{{ $input }}" placeholder="e.g. *714*5#">
                            <p class="mt-1 text-xs text-gray-500">Fallback only: used as the base code for generated vendor USSD codes when the Global USSD Base Code is blank. Changing it can change every vendor's generated code.</p>
                        </div>

                        <div>
                            <label for="ussd_support_number" class="block text-sm font-medium text-gray-700 mb-2">Support Number</label>
                            <input type="text" name="ussd_support_number" id="ussd_support_number"
                                   value="{{ $settings['ussd_support_number'] ?? '' }}"
                                   class="{{ $input }}" placeholder="e.g. 0531192005">
                            <p class="mt-1 text-xs text-gray-500">Shown at the bottom of every USSD menu screen.</p>
                        </div>

                        <div>
                            <label for="ussd_welcome_message" class="block text-sm font-medium text-gray-700 mb-2">
                                Welcome Message <span class="text-red-500">*</span>
                            </label>
                            <input type="text" name="ussd_welcome_message" id="ussd_welcome_message"
                                   value="{{ $settings['ussd_welcome_message'] ?? 'Welcome to XTRA4U' }}"
                                   maxlength="100" class="{{ $input }}" required>
                            <p class="mt-1 text-xs text-gray-500">Greeting shown on the main menu. Max 100 characters.</p>
                        </div>
                    </div>

                    <div class="pt-6 border-t border-gray-200">
                        <h3 class="text-sm font-semibold text-gray-900 mb-4">Session Controls</h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label for="ussd_session_timeout_seconds" class="block text-sm font-medium text-gray-700 mb-2">Session Timeout (seconds)</label>
                                <input type="number" name="ussd_session_timeout_seconds" id="ussd_session_timeout_seconds"
                                       value="{{ $settings['ussd_session_timeout_seconds'] ?? 240 }}" min="30" max="600" class="{{ $input }}">
                                <p class="mt-1 text-xs text-gray-500">Idle time before a session is rejected. Between 30 and 600.</p>
                            </div>
                            <div>
                                <label for="ussd_max_requests_per_session" class="block text-sm font-medium text-gray-700 mb-2">Max Requests per Session</label>
                                <input type="number" name="ussd_max_requests_per_session" id="ussd_max_requests_per_session"
                                       value="{{ $settings['ussd_max_requests_per_session'] ?? 20 }}" min="1" max="100" class="{{ $input }}">
                                <p class="mt-1 text-xs text-gray-500">Caps how many screens one session may request.</p>
                            </div>
                            <div>
                                <label for="ussd_max_retry_attempts" class="block text-sm font-medium text-gray-700 mb-2">Max Retry Attempts</label>
                                <input type="number" name="ussd_max_retry_attempts" id="ussd_max_retry_attempts"
                                       value="{{ $settings['ussd_max_retry_attempts'] ?? 3 }}" min="1" max="10" class="{{ $input }}">
                                <p class="mt-1 text-xs text-gray-500">Invalid entries allowed before the session is ended.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            {{-- ===================== SECTION B: MOOLRE ===================== --}}
            <section class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden" id="ussd-moolre">
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">B. Moolre USSD Gateway</h2>
                        <p class="text-xs text-gray-500 mt-0.5">Callers are routed to a vendor by the code they dial.</p>
                    </div>
                    <div class="flex flex-wrap gap-1">
                        {!! $status['master'] ? $badge('green', 'Enabled') : $badge('gray', 'Disabled (master off)') !!}
                        {!! $status['legacy_protected'] ? $badge('green', 'Protected') : $badge('amber', 'Unprotected') !!}
                    </div>
                </div>
                <div class="p-6 space-y-6">
                    <p class="text-xs text-gray-500">
                        Moolre has no enable switch of its own: it is on whenever the master channel is on.
                        @if ($status['legacy_protected'])
                            Callers are restricted by an IP allowlist and/or shared secret.
                        @else
                            No IP allowlist or shared secret is set, so any caller can reach this endpoint.
                        @endif
                    </p>

                    {{-- Endpoint --}}
                    <div class="space-y-4" id="endpoint-legacy">
                        <h3 class="text-sm font-semibold text-gray-900">Endpoint</h3>
                        <p class="text-sm text-gray-600">Give Moolre this callback URL, and ask them to assign a service code (e.g. <strong>*714*X#</strong>) and use <strong>POST</strong>.</p>
                        <div>
                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Callback URL</p>
                            <div class="flex items-center gap-2">
                                <code id="ussd-callback-url"
                                      class="flex-1 block bg-gray-100 text-gray-800 text-sm rounded-lg px-4 py-2 font-mono break-all">{{ route('api.ussd') }}</code>
                                <button type="button" onclick="copyCallbackUrl('ussd-callback-url', this)"
                                        class="flex-shrink-0 inline-flex items-center px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 bg-white hover:bg-gray-50 transition-colors">Copy</button>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Method</p>
                                <code class="block bg-gray-100 text-gray-800 text-sm rounded-lg px-4 py-2 font-mono">POST</code>
                            </div>
                            <div>
                                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Content-Type</p>
                                <code class="block bg-gray-100 text-gray-800 text-sm rounded-lg px-4 py-2 font-mono break-all">application/x-www-form-urlencoded</code>
                            </div>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">Request fields</p>
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <thead>
                                        <tr class="bg-gray-50 border-b border-gray-200">
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Field</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <tr><td class="px-4 py-2 font-mono text-brand-violet">sessionId</td><td class="px-4 py-2 text-gray-600">Unique session identifier (6&ndash;128 characters)</td></tr>
                                        <tr><td class="px-4 py-2 font-mono text-brand-violet">phoneNumber</td><td class="px-4 py-2 text-gray-600">Customer's phone number</td></tr>
                                        <tr><td class="px-4 py-2 font-mono text-brand-violet">network</td><td class="px-4 py-2 text-gray-600">Mobile network (MTN, Telecel, AT)</td></tr>
                                        <tr><td class="px-4 py-2 font-mono text-brand-violet">serviceCode</td><td class="px-4 py-2 text-gray-600">The USSD service code dialled</td></tr>
                                        <tr><td class="px-4 py-2 font-mono text-brand-violet">text</td><td class="px-4 py-2 text-gray-600">Concatenated user input (e.g. <code class="{{ $codeChip }}">1*2*0244123456</code>)</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Response</p>
                            <p class="text-sm text-gray-600"><code class="{{ $codeChip }}">text/plain</code> body starting with <code class="{{ $codeChip }}">CON </code> (continue) or <code class="{{ $codeChip }}">END </code> (finish).</p>
                        </div>
                    </div>

                    {{-- Vendor routing --}}
                    <div class="pt-6 border-t border-gray-200">
                        <h3 class="text-sm font-semibold text-gray-900 mb-1">Vendor routing</h3>
                        <p class="text-xs text-gray-500 mb-4">
                            Each subscribed vendor is issued a code in the form
                            <strong>&lt;base code&gt;&lt;plan extension&gt;*&lt;vendor id&gt;#</strong>, for example <strong>*203*45*102#</strong>.
                        </p>
                        <label for="ussd_default_vendor_id" class="block text-sm font-medium text-gray-700 mb-2">Default Vendor</label>
                        <select name="ussd_default_vendor_id" id="ussd_default_vendor_id" class="{{ $input }}">
                            <option value="">None (refuse base-code dials)</option>
                            @foreach ($vendors as $vendor)
                                <option value="{{ $vendor->id }}"
                                    {{ ($settings['ussd_default_vendor_id'] ?? '') == $vendor->id ? 'selected' : '' }}>
                                    {{ $vendor->name }} ({{ $vendor->vendor_code }})
                                </option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-gray-500">
                            Used when a customer dials the base code alone, without a vendor extension. If none is selected, those calls are refused; there is no automatic fallback to another vendor. Dials that name a vendor are unaffected. GWO SEVO does not use this setting.
                        </p>
                    </div>

                    {{-- Authentication --}}
                    <div class="pt-6 border-t border-gray-200">
                        <h3 class="text-sm font-semibold text-gray-900 mb-1">Moolre authentication</h3>
                        <p class="text-xs text-gray-500 mb-4">
                            Restricts who may post to the Moolre endpoint. Without these, anyone can drive the menu and consume vendors' paid sessions.
                            These credentials are separate from GWO SEVO's and do not work on its endpoint.
                        </p>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="ussd_gateway_ip_allowlist" class="block text-sm font-medium text-gray-700 mb-2">Moolre IP Allowlist</label>
                                <input type="text" name="ussd_gateway_ip_allowlist" id="ussd_gateway_ip_allowlist"
                                       value="{{ $settings['ussd_gateway_ip_allowlist'] ?? '' }}"
                                       class="{{ $input }}" placeholder="e.g. 41.210.0.0/16, 197.251.1.20">
                                <p class="mt-1 text-xs text-gray-500">Comma-separated IPs or CIDR blocks. Leave blank to allow any source IP.</p>
                            </div>
                            <div>
                                <label for="ussd_gateway_secret" class="block text-sm font-medium text-gray-700 mb-2">
                                    Moolre Shared Secret
                                    @if ($hasGatewaySecret ?? false)
                                        <span class="ml-1 text-xs font-normal text-green-600">&bull; configured</span>
                                    @endif
                                </label>
                                <input type="password" name="ussd_gateway_secret" id="ussd_gateway_secret"
                                       autocomplete="new-password" class="{{ $input }}"
                                       placeholder="{{ ($hasGatewaySecret ?? false) ? 'Leave blank to keep the current secret' : 'At least 16 characters' }}">
                                <p class="mt-1 text-xs text-gray-500">Stored encrypted and never displayed again.</p>
                                @if ($hasGatewaySecret ?? false)
                                    <label class="mt-2 inline-flex items-center text-xs text-gray-600">
                                        <input type="checkbox" name="ussd_gateway_secret_clear" value="1"
                                               class="rounded border-gray-300 text-brand-violet focus:ring-brand-violet mr-2">
                                        Remove the stored secret
                                    </label>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            {{-- ===================== SECTION C: GWO SEVO ===================== --}}
            <section class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden" id="ussd-gwosevo">
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">C. GWO SEVO USSD Gateway</h2>
                        <p class="text-xs text-gray-500 mt-0.5">Every call is served for one designated vendor.</p>
                    </div>
                    <div class="flex flex-wrap gap-1">
                        {!! $status['gwosevo_enabled'] ? $badge('green', 'Enabled') : $badge('gray', 'Disabled') !!}
                        {!! $status['gwosevo_ready'] ? $badge('green', 'Configuration ready') : $badge('amber', 'Configuration incomplete') !!}
                        @if ($status['gwosevo_enabled'] && ! $status['master'])
                            {!! $badge('red', 'Blocked by master') !!}
                        @endif
                    </div>
                </div>
                <div class="p-6 space-y-6">
                    <p class="text-xs text-gray-500">
                        Has its own enable setting below and also needs the master channel on.
                        @if ($status['gwosevo_ready'])
                            Authentication and vendor are configured.
                        @else
                            Incomplete: still needs {{ implode(' and ', $status['gwosevo_missing']) }}. Calls are refused until then.
                        @endif
                    </p>

                    <div>
                        <input type="hidden" name="ussd_gwosevo_enabled" value="0">
                        <label class="inline-flex items-center text-sm text-gray-700">
                            <input type="checkbox" name="ussd_gwosevo_enabled" value="1"
                                   {{ ($settings['ussd_gwosevo_enabled'] ?? '0') === '1' ? 'checked' : '' }}
                                   class="rounded border-gray-300 text-brand-violet focus:ring-brand-violet mr-2">
                            Enable GWO SEVO
                        </label>
                    </div>

                    {{-- Endpoint --}}
                    <div class="space-y-4" id="endpoint-gwosevo">
                        <h3 class="text-sm font-semibold text-gray-900">Endpoint</h3>
                        <div>
                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Callback URL</p>
                            <div class="flex items-center gap-2">
                                <code id="gwosevo-callback-url"
                                      class="flex-1 block bg-gray-100 text-gray-800 text-sm rounded-lg px-4 py-2 font-mono break-all">{{ route('api.ussd.gwosevo') }}</code>
                                <button type="button" onclick="copyCallbackUrl('gwosevo-callback-url', this)"
                                        class="flex-shrink-0 inline-flex items-center px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 bg-white hover:bg-gray-50 transition-colors">Copy</button>
                            </div>
                            <p class="mt-1 text-xs text-gray-500">If the gateway can only be given a URL, append <span class="font-mono">?key=&lt;secret&gt;</span>.</p>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Method</p>
                                <code class="block bg-gray-100 text-gray-800 text-sm rounded-lg px-4 py-2 font-mono">POST</code>
                            </div>
                            <div>
                                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Content-Type</p>
                                <code class="block bg-gray-100 text-gray-800 text-sm rounded-lg px-4 py-2 font-mono break-all">application/json</code>
                            </div>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">Request fields</p>
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <thead>
                                        <tr class="bg-gray-50 border-b border-gray-200">
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Field</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <tr><td class="px-4 py-2 font-mono text-brand-violet">sessionID</td><td class="px-4 py-2 text-gray-600">Gateway session identifier (up to 64 characters)</td></tr>
                                        <tr><td class="px-4 py-2 font-mono text-brand-violet">ussdServiceOp</td><td class="px-4 py-2 text-gray-600"><code class="{{ $codeChip }}">1</code> = start, <code class="{{ $codeChip }}">18</code> = continue</td></tr>
                                        <tr><td class="px-4 py-2 font-mono text-brand-violet">msisdn</td><td class="px-4 py-2 text-gray-600">Customer's phone number (233&hellip;, +233&hellip; or 0&hellip;)</td></tr>
                                        <tr><td class="px-4 py-2 font-mono text-brand-violet">network</td><td class="px-4 py-2 text-gray-600"><code class="{{ $codeChip }}">01</code> MTN, <code class="{{ $codeChip }}">02</code> Telecel, <code class="{{ $codeChip }}">04</code> AT</td></tr>
                                        <tr><td class="px-4 py-2 font-mono text-brand-violet">ussdString</td><td class="px-4 py-2 text-gray-600">The customer's latest input (empty on start)</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Response</p>
                            <p class="text-sm text-gray-600">
                                JSON <code class="{{ $codeChip }}">{ "message", "ussdServiceOp", "sessionID" }</code>, where
                                <code class="{{ $codeChip }}">ussdServiceOp</code> is <code class="{{ $codeChip }}">2</code> (continue) or <code class="{{ $codeChip }}">17</code> (end).
                            </p>
                        </div>
                    </div>

                    {{-- Vendor --}}
                    <div class="pt-6 border-t border-gray-200">
                        <h3 class="text-sm font-semibold text-gray-900 mb-1">Designated vendor</h3>
                        <p class="text-xs text-gray-500 mb-4">GWO SEVO does not tell us which code was dialled, so every call on this gateway is served for the single vendor chosen here.</p>
                        <label for="ussd_gwosevo_vendor_id" class="block text-sm font-medium text-gray-700 mb-2">Vendor served</label>
                        <select name="ussd_gwosevo_vendor_id" id="ussd_gwosevo_vendor_id" class="{{ $input }}">
                            <option value="">None (refuse all calls)</option>
                            @foreach ($vendors as $vendor)
                                <option value="{{ $vendor->id }}" {{ (string) ($settings['ussd_gwosevo_vendor_id'] ?? '') === (string) $vendor->id ? 'selected' : '' }}>
                                    {{ $vendor->name }} ({{ $vendor->vendor_code }})
                                </option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-gray-500">This vendor's subscription and paid sessions are used. The Moolre default vendor is not used here.</p>
                    </div>

                    {{-- Authentication --}}
                    <div class="pt-6 border-t border-gray-200">
                        <h3 class="text-sm font-semibold text-gray-900 mb-1">GWO SEVO authentication</h3>
                        <p class="text-xs text-gray-500 mb-4">
                            The endpoint refuses all traffic until an IP allowlist or a shared secret is set (fails closed).
                            These credentials are separate from Moolre's and do not work on its endpoint.
                        </p>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="ussd_gwosevo_ip_allowlist" class="block text-sm font-medium text-gray-700 mb-2">GWO SEVO IP Allowlist</label>
                                <input type="text" name="ussd_gwosevo_ip_allowlist" id="ussd_gwosevo_ip_allowlist"
                                       value="{{ $settings['ussd_gwosevo_ip_allowlist'] ?? '' }}"
                                       class="{{ $input }}" placeholder="Comma-separated IPs or CIDR blocks">
                            </div>
                            <div>
                                <label for="ussd_gwosevo_secret" class="block text-sm font-medium text-gray-700 mb-2">
                                    GWO SEVO Shared Secret
                                    @if ($hasGwoSevoSecret ?? false)
                                        <span class="ml-1 text-xs font-normal text-green-600">&bull; configured</span>
                                    @endif
                                </label>
                                <input type="password" name="ussd_gwosevo_secret" id="ussd_gwosevo_secret"
                                       autocomplete="new-password" class="{{ $input }}"
                                       placeholder="{{ ($hasGwoSevoSecret ?? false) ? 'Leave blank to keep the current secret' : 'At least 16 characters' }}">
                                <p class="mt-1 text-xs text-gray-500">Sent as X-Ussd-Secret / X-Api-Key / Bearer, or as <span class="font-mono">?key=</span> on the callback URL. Stored encrypted.</p>
                                @if ($hasGwoSevoSecret ?? false)
                                    <label class="mt-2 inline-flex items-center text-xs text-gray-600">
                                        <input type="checkbox" name="ussd_gwosevo_secret_clear" value="1"
                                               class="rounded border-gray-300 text-brand-violet focus:ring-brand-violet mr-2">
                                        Remove the stored secret
                                    </label>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <p class="text-xs text-gray-500">Saving writes each section's own settings only; Moolre and GWO SEVO values are stored under separate keys and never overwrite one another.</p>
                <button type="submit"
                        class="inline-flex items-center justify-center rounded-lg font-medium shadow-sm transition focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 px-4 py-2 text-sm bg-brand-violet text-white hover:bg-brand-violet-deep focus:ring-brand-violet">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                    Save Settings
                </button>
            </div>
        </form>

    </div>
@push('scripts')
<script>
function copyCallbackUrl(id, btn) {
    const url = document.getElementById(id).textContent.trim();
    navigator.clipboard.writeText(url).then(() => {
        const original = btn.textContent;
        btn.textContent = 'Copied!';
        btn.classList.add('text-green-600', 'border-green-300');
        setTimeout(() => { btn.textContent = original; btn.classList.remove('text-green-600', 'border-green-300'); }, 2000);
    });
}
</script>
@endpush
</x-admin-layout>
@endsection
