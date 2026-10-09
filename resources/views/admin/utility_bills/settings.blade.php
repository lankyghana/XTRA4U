@extends('layouts.admin')

@section('content')
@php
    $formId = 'utility-settings-form';
    $serviceOn = filter_var(old('enabled', $enabled), FILTER_VALIDATE_BOOLEAN);
    $source = $credentials['source'];
    $keyUsable = $health['configured'];

    // Provider connection -> badge tone + label (display only).
    [$connTone, $connLabel] = match ($connection) {
        'connected' => ['enabled', 'Connected'],
        'rejected' => ['failed', 'Invalid API key'],
        'unavailable' => ['degraded', 'Connection unavailable'],
        default => ['failed', 'Not connected'],
    };

    $money = fn ($v) => 'GHS '.number_format((float) $v, 2);
    $allowed = ($limits['min'] || $limits['max'])
        ? ($limits['min'] ? $money($limits['min']) : '—').' – '.($limits['max'] ? $money($limits['max']) : '—')
        : null;

    $inputClass = 'w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-brand-violet focus:ring-brand-violet';
@endphp
<x-admin-layout title="Utility Bills" subtitle="Manage billers, provider connection and vendor commissions." active="utility-bills-settings">
    <x-slot:actions>
        @if ($health['attention'] > 0)
            <a href="{{ route('admin.utility-bill-sales.index', ['fulfillment' => 'attention']) }}" class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-1 text-sm font-medium text-red-800 ring-1 ring-inset ring-red-600/20 hover:bg-red-100">{{ $health['attention'] }} {{ \Illuminate\Support\Str::plural('order', $health['attention']) }} need attention</a>
        @endif
        <span class="text-sm text-gray-500">Service</span>
        <x-admin.status :status="$enabled ? 'enabled' : 'disabled'" :label="$enabled ? 'Active' : 'Disabled'" class="!px-2.5 !py-1 !text-sm" />
    </x-slot:actions>

    <div class="max-w-6xl space-y-5"
         x-data="{ keyOpen: {{ $errors->has('api_key') ? 'true' : 'false' }}, dirty: false, showAllHistory: false, svc: {{ $serviceOn ? 'true' : 'false' }}, connOk: {{ $connection === 'connected' ? 'true' : 'false' }},
                   openKey() { this.keyOpen = true; this.$nextTick(() => this.$refs.apiKey && this.$refs.apiKey.focus()); } }"
         @input="if ($event.target.getAttribute('form') === '{{ $formId }}') dirty = true"
         @change="if ($event.target.getAttribute('form') === '{{ $formId }}') dirty = true">

        @if (session('success'))
            <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800" role="status">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                <p class="font-medium">Please fix the following:</p>
                <ul class="ml-5 list-disc">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        @if ($walletPausedAt ?? null)
            <div class="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="alert">
                <div class="min-w-0">
                    <p class="font-semibold">New sales paused: KiNG FLEXY wallet too low</p>
                    <p class="mt-0.5">Since {{ \Illuminate\Support\Carbon::parse($walletPausedAt)->format('d M Y, H:i') }}. Paid orders keep retrying with their existing references. Sales resume automatically on the next successful provider payment.</p>
                </div>
                <form method="POST" action="{{ route('admin.utility-bills.settings.resume-wallet') }}" onsubmit="return confirm('Have you topped up the KiNG FLEXY wallet? This reopens sales and retries waiting orders now.');">
                    @csrf
                    <button type="submit" class="inline-flex items-center rounded-lg bg-amber-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-500">Resume sales</button>
                </form>
            </div>
        @endif

        {{-- All service / biller inputs below attach to this form via the form="" attribute, so the
             provider card (which has its own independent forms) can sit between them. --}}
        <form id="{{ $formId }}" method="POST" action="{{ route('admin.utility-bills.settings.update') }}">
            @csrf
            @method('PUT')
        </form>

        <div class="grid gap-5 lg:grid-cols-2">
            {{-- SERVICE --}}
            <section class="rounded-xl border border-gray-200 bg-white p-4 sm:p-5" aria-labelledby="svc-h">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h2 id="svc-h" class="text-base font-semibold text-gray-900">Service status</h2>
                        <p class="mt-1 text-sm text-gray-600" x-text="svc ? 'Utility Bills are accepting new orders.' : 'New Utility Bill orders are paused. Existing paid orders will continue processing.'">
                            {{ $serviceOn ? 'Utility Bills are accepting new orders.' : 'New Utility Bill orders are paused. Existing paid orders will continue processing.' }}
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="w-7 text-right text-xs font-semibold text-gray-600" x-text="svc ? 'ON' : 'OFF'" aria-hidden="true">{{ $serviceOn ? 'ON' : 'OFF' }}</span>
                        <input type="hidden" name="enabled" value="0" form="{{ $formId }}">
                        <x-admin.switch name="enabled" label="Utility Bills enabled for new sales" :checked="$serviceOn" :form="$formId" model="svc" />
                    </div>
                </div>
                <div class="mt-4">
                    <label for="maintenance_message" class="block text-sm font-medium text-gray-700">Customer message when unavailable <span class="font-normal text-gray-400">(optional)</span></label>
                    <input id="maintenance_message" type="text" name="maintenance_message" form="{{ $formId }}" maxlength="255"
                           value="{{ old('maintenance_message', $message) }}"
                           class="mt-1 {{ $inputClass }}"
                           placeholder="Utility Bills are temporarily unavailable. Please try again later.">
                </div>

                {{-- Storefront card image: its own form, saved immediately (not part of the settings form). --}}
                <div class="mt-4 border-t border-gray-100 pt-4">
                    <p class="text-sm font-medium text-gray-700">Storefront image</p>
                    <div class="mt-2 flex flex-wrap items-center gap-3">
                        @if ($imageUrl)
                            <img src="{{ $imageUrl }}" alt="Utility Bills storefront image" class="h-12 w-12 rounded-md border border-gray-200 object-cover">
                        @else
                            <span class="flex h-12 w-12 items-center justify-center rounded-md border border-dashed border-gray-300 text-xs text-gray-400">None</span>
                        @endif
                        <form method="POST" action="{{ route('admin.utility-bills.settings.image') }}" enctype="multipart/form-data" class="flex flex-wrap items-center gap-2">
                            @csrf
                            <input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp" required
                                   class="max-w-[14rem] text-sm text-gray-600 file:mr-2 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-gray-700 hover:file:bg-gray-200">
                            <button type="submit" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brand-violet">{{ $imageUrl ? 'Replace' : 'Upload' }}</button>
                        </form>
                        @if ($imageUrl)
                            <form method="POST" action="{{ route('admin.utility-bills.settings.image') }}" onsubmit="return confirm('Remove the Utility Bills storefront image?');">
                                @csrf
                                <input type="hidden" name="action" value="remove">
                                <button type="submit" class="text-sm text-red-700 underline hover:text-red-800 focus:outline-none focus:ring-2 focus:ring-red-500">Remove</button>
                            </form>
                        @endif
                    </div>
                    <p class="mt-1 text-xs text-gray-500">Shown on the Utility Bills card in every vendor storefront. JPEG, PNG, GIF or WebP, max 2MB. Saved immediately.</p>
                    @error('image')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                </div>
            </section>

            {{-- PROVIDER --}}
            <section class="rounded-xl border border-gray-200 bg-white p-4 sm:p-5" aria-labelledby="prov-h">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 id="prov-h" class="text-base font-semibold text-gray-900">KiNG FLEXY GH</h2>
                        <p class="text-sm text-gray-500">Utility Bills fulfillment provider</p>
                    </div>
                    <x-admin.status :status="$connTone" :label="$connLabel" />
                </div>

                <dl class="mt-4 grid grid-cols-[7rem_1fr] gap-x-3 gap-y-2.5 text-sm">
                    <dt class="text-gray-500">API key</dt>
                    <dd class="min-w-0 text-gray-900">
                        @if ($source === 'none')
                            <span class="font-medium">Not configured</span>
                        @elseif (! $keyUsable)
                            <span class="font-medium text-red-700">Not a Commission key</span>
                            <span class="block text-xs text-gray-500">Commission keys start with kf_cs_. Data keys do not work for utilities.</span>
                        @elseif ($connection === 'rejected')
                            <span class="font-medium text-red-700">Rejected by provider</span>
                        @else
                            <span class="font-medium">{{ $source === 'admin' ? 'Configured in Admin' : 'Configured from environment' }}</span>
                        @endif
                        @if ($source !== 'none')
                            <span class="ml-1 text-xs text-gray-400" title="Last four characters only">&bull;&bull;&bull;&bull;{{ $credentials['last4'] }}</span>
                        @endif
                    </dd>

                    <dt class="text-gray-500">Catalog</dt>
                    <dd>
                        <x-admin.status :status="$health['catalog_available'] ? 'healthy' : ($health['catalog_stale'] ? 'degraded' : 'failed')"
                                        :label="$health['catalog_available'] ? 'Available' : ($health['catalog_stale'] ? 'Stale copy' : 'Unavailable')" />
                    </dd>

                    <dt class="text-gray-500">Last sync</dt>
                    <dd class="font-medium text-gray-900">{{ $health['last_catalog_success']?->diffForHumans() ?? 'Never' }}</dd>

                    <dt class="text-gray-500">Orders</dt>
                    <dd class="text-gray-900">
                        <a href="{{ route('admin.utility-bill-sales.index', ['fulfillment' => 'attention']) }}"
                           class="font-medium hover:underline {{ $health['attention'] ? 'text-red-700' : 'text-gray-900' }}">{{ $health['attention'] }} need attention</a>
                        <span class="text-gray-400">&middot;</span> {{ $health['in_flight'] }} processing
                    </dd>
                </dl>

                <div class="mt-4 border-t border-gray-100 pt-4">
                    <div x-show="!keyOpen" class="flex flex-wrap items-center gap-3">
                        <button type="button" @click="openKey()" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brand-violet">
                            {{ $source === 'none' ? 'Configure API key' : 'Change API key' }}
                        </button>
                        @if ($source === 'admin')
                            <form method="POST" action="{{ route('admin.utility-bills.settings.credentials') }}" onsubmit="return confirm('Remove the key saved in Admin and use the environment key instead?');">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="action" value="clear">
                                <button type="submit" class="text-sm text-red-700 underline hover:text-red-800 focus:outline-none focus:ring-2 focus:ring-red-500">Remove override and use environment key</button>
                            </form>
                        @endif
                    </div>

                    <form x-show="keyOpen" x-cloak method="POST" action="{{ route('admin.utility-bills.settings.credentials') }}" autocomplete="off" class="space-y-2">
                        @csrf
                        @method('PUT')
                        <label for="api_key" class="block text-sm font-medium text-gray-700">New Commission Services key <span class="font-normal text-gray-400">(kf_cs_&hellip;)</span></label>
                        <div class="flex flex-wrap gap-2">
                            <input id="api_key" x-ref="apiKey" type="password" name="api_key" autocomplete="new-password" maxlength="255"
                                   class="min-w-0 flex-1 {{ $inputClass }}" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;">
                            <button type="submit" class="inline-flex items-center rounded-lg bg-brand-violet px-4 py-2 text-sm font-semibold text-white hover:bg-brand-violet-deep focus:outline-none focus:ring-2 focus:ring-brand-violet focus:ring-offset-2">Save key</button>
                            <button type="button" @click="keyOpen = false" class="inline-flex items-center rounded-lg px-3 py-2 text-sm text-gray-600 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-brand-violet">Cancel</button>
                        </div>
                        <p class="text-xs text-gray-500">Stored encrypted and never shown again. A key saved here takes priority over the environment key.</p>
                    </form>
                </div>

                @if ($health['recent_errors']->isNotEmpty())
                    <details class="mt-3 text-sm">
                        <summary class="cursor-pointer text-gray-700">Recent provider issues</summary>
                        <ul class="mt-2 space-y-1 text-xs text-gray-600">
                            @foreach ($health['recent_errors'] as $ev)
                                <li>{{ $ev->created_at?->format('d M H:i') }} &middot; {{ $ev->order?->public_ref }} &middot; {{ $ev->kind }} {{ $ev->detail ? '— '.$ev->detail : '' }}</li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </section>
        </div>

        {{-- BILLERS & COMMISSIONS --}}
        <section aria-labelledby="bill-h" class="space-y-3">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <div>
                    <h2 id="bill-h" class="text-lg font-semibold text-gray-900">Billers &amp; commissions</h2>
                    <p class="text-sm text-gray-600">Vendors earn the configured commission after a bill is successfully completed. Changes apply only to new orders.</p>
                </div>
            </div>

            <details class="rounded-lg border border-gray-200 bg-white px-4 py-2.5 text-sm text-gray-700">
                <summary class="cursor-pointer font-medium text-brand-violet-deep">Learn about commissions</summary>
                <div class="mt-2 space-y-2 text-gray-600">
                    <p>A biller is sold only when the provider reports it available <em>and</em> you enable it here.</p>
                    <p>Vendor commission is calculated on the bill face value and frozen on each order when it is created.</p>
                    <p>This is separate from the commission the provider pays XTRA4U, which is a variable share of KiNG FLEXY's own commission and is paid only when a bill completes. Provider commission is not guaranteed profit. You may configure vendor commission above it; nothing blocks that, it is your business decision.</p>
                </div>
            </details>

            @if ($connection !== 'connected' && count($rows) > 0)
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-900" role="status">
                    @if ($connection === 'rejected')
                        The provider rejected the API key. Showing the last known billers; nothing can be sold until the key is fixed.
                    @elseif ($connection === 'not_configured')
                        No working API key. Showing saved configuration only; nothing can be sold until a key is added.
                    @else
                        The provider is temporarily unavailable. Showing the last known billers; your saved configuration is unchanged.
                    @endif
                </div>
            @endif

            @if (count($rows) === 0)
                <div class="rounded-xl border border-dashed border-gray-300 bg-white">
                    @if ($connection === 'not_configured')
                        <x-admin.empty title="No billers loaded" description="Connect your KiNG FLEXY Commission Services key to load available billers.">
                            <button type="button" @click="openKey()" class="inline-flex items-center rounded-lg bg-brand-violet px-4 py-2 text-sm font-semibold text-white hover:bg-brand-violet-deep focus:outline-none focus:ring-2 focus:ring-brand-violet focus:ring-offset-2">Configure API key</button>
                        </x-admin.empty>
                    @elseif ($connection === 'rejected')
                        <x-admin.empty title="Unable to connect to KiNG FLEXY" description="Check the API key and try again.">
                            <button type="button" @click="openKey()" class="inline-flex items-center rounded-lg bg-brand-violet px-4 py-2 text-sm font-semibold text-white hover:bg-brand-violet-deep focus:outline-none focus:ring-2 focus:ring-brand-violet focus:ring-offset-2">Update API key</button>
                        </x-admin.empty>
                    @elseif ($connection === 'unavailable')
                        <x-admin.empty title="Provider temporarily unavailable" description="Your saved configuration is unchanged. Try refreshing the catalog later.">
                            <a href="{{ route('admin.utility-bills.settings') }}" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brand-violet">Refresh</a>
                        </x-admin.empty>
                    @else
                        <x-admin.empty title="No billers listed" description="The provider has not listed any billers yet." />
                    @endif
                </div>
            @else
                <div class="grid gap-4 md:grid-cols-2">
                    @foreach ($rows as $row)
                        @php
                            $k = $row['key'];
                            $isOn = filter_var(old("billers.$k.is_enabled", $row['is_enabled']), FILTER_VALIDATE_BOOLEAN);
                            $type = old("billers.$k.commission_type", $row['commission_type']);
                            $eco = $row['economics'] ?? ['n' => 0, 'warn' => false];
                            $prov = (bool) $row['provider_enabled'];
                        @endphp
                        <article class="flex flex-col rounded-xl border border-gray-200 bg-white p-4 sm:p-5"
                                 x-data="{ on: {{ $isOn ? 'true' : 'false' }}, type: @js($type), prov: {{ $prov ? 'true' : 'false' }} }">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h3 class="truncate text-base font-semibold text-gray-900">{{ $row['label'] }}</h3>
                                    <p class="text-xs text-gray-500">{{ $k }}@if ($row['account_label']) &middot; {{ $row['account_label'] }}@endif</p>
                                </div>
                                <x-admin.status :status="$prov ? 'enabled' : 'failed'" :label="$prov ? 'Available' : 'Unavailable'" />
                            </div>

                            @if (! $prov)
                                <p class="mt-3 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900">
                                    {{ $row['listed'] ? 'Provider has temporarily disabled this biller.' : 'Provider no longer lists this biller.' }}
                                    Cannot be sold until the provider enables it. Your settings below stay saved.
                                </p>
                            @endif

                            <div class="mt-3 flex items-center justify-between gap-3">
                                <span class="text-sm font-medium text-gray-700">XTRA4U sales</span>
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-semibold text-gray-600" x-text="on ? 'ON' : 'OFF'" aria-hidden="true">{{ $isOn ? 'ON' : 'OFF' }}</span>
                                    <input type="hidden" name="billers[{{ $k }}][is_enabled]" value="0" form="{{ $formId }}">
                                    <x-admin.switch :name="'billers['.$k.'][is_enabled]'" :label="'XTRA4U sales for '.$row['label']" :checked="$isOn" :form="$formId" model="on" />
                                </div>
                            </div>

                            {{-- Final availability: master switch AND biller switch AND provider biller AND valid key. --}}
                            @php
                                $sellNow = $serviceOn && $isOn && $prov && $connection === 'connected';
                                // Provider before the XTRA4U switch: turning sales on cannot make a provider-disabled biller sell.
                                $whyNot = ! $serviceOn ? 'Utility Bills is off' : (! $prov ? 'provider unavailable' : (! $isOn ? 'XTRA4U sales are off' : 'provider not connected'));
                            @endphp
                            <p class="mt-2 text-xs font-medium"
                               :class="(svc && on && prov && connOk) ? 'text-green-700' : 'text-gray-500'"
                               x-text="(svc && on && prov && connOk) ? '● Selling now' : '○ Not selling: ' + (!svc ? 'Utility Bills is off' : (!prov ? 'provider unavailable' : (!on ? 'XTRA4U sales are off' : 'provider not connected')))">{{ $sellNow ? '● Selling now' : '○ Not selling: '.$whyNot }}</p>

                            <div class="mt-4">
                                <p class="text-sm font-medium text-gray-700">Vendor commission</p>
                                <div class="mt-1.5 grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)] gap-2">
                                    <div>
                                        <label for="type-{{ $k }}" class="sr-only">Commission type for {{ $row['label'] }}</label>
                                        <select id="type-{{ $k }}" name="billers[{{ $k }}][commission_type]" form="{{ $formId }}" x-model="type" class="{{ $inputClass }}">
                                            <option value="percentage" @selected($type === 'percentage')>Percentage</option>
                                            <option value="fixed" @selected($type === 'fixed')>Fixed amount</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label for="val-{{ $k }}" class="sr-only">Commission value for {{ $row['label'] }}</label>
                                        <div class="flex rounded-md shadow-sm">
                                            <span x-show="type === 'fixed'" @if ($type !== 'fixed') x-cloak @endif class="inline-flex items-center rounded-l-md border border-r-0 border-gray-300 bg-gray-50 px-2.5 text-sm text-gray-600">GHS</span>
                                            <input id="val-{{ $k }}" type="text" inputmode="decimal" name="billers[{{ $k }}][commission_value]" form="{{ $formId }}"
                                                   value="{{ old("billers.$k.commission_value", $row['commission_value']) }}"
                                                   class="block w-full min-w-0 flex-1 border-gray-300 text-sm focus:z-10 focus:border-brand-violet focus:ring-brand-violet"
                                                   :class="type === 'fixed' ? 'rounded-r-md' : 'rounded-l-md'">
                                            <span x-show="type === 'percentage'" @if ($type !== 'percentage') x-cloak @endif class="inline-flex items-center rounded-r-md border border-l-0 border-gray-300 bg-gray-50 px-2.5 text-sm text-gray-600">%</span>
                                        </div>
                                    </div>
                                </div>
                                @error("billers.$k.commission_value")<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                            </div>

                            @if ($allowed)
                                <div class="mt-4 text-sm">
                                    <p class="font-medium text-gray-700">Allowed amount</p>
                                    <p class="text-gray-900">{{ $allowed }} <span class="text-xs text-gray-400">set by the provider</span></p>
                                </div>
                            @endif

                            <div class="mt-4 space-y-2 text-sm">
                                <p class="font-medium text-gray-700">Provider commission</p>
                                @if ($eco['n'] === 0)
                                    <p class="text-xs text-gray-500">No completed-sale data yet</p>
                                @else
                                    <p class="text-gray-900">Recent actual: <strong>{{ $eco['observed_pct'] }}%</strong> <span class="text-xs text-gray-500">(avg GHS {{ $eco['observed_avg'] }} over {{ $eco['n'] }} completed {{ \Illuminate\Support\Str::plural('order', $eco['n']) }})</span></p>
                                    @if ($eco['warn'])
                                        <div class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900" role="note">
                                            <p class="font-semibold">&#9888; Vendor commission may exceed recent provider commission.</p>
                                            <p class="mt-0.5">Each sale could cost XTRA4U more than the provider pays it. Informational only; saving is never blocked.</p>
                                        </div>
                                    @endif
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Single save action for the service + biller form (credentials save separately above). --}}
        <div class="sticky bottom-3 z-10 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 bg-white/95 px-4 py-3 shadow-lg backdrop-blur">
            <p class="text-sm text-gray-600">
                <span x-show="dirty" x-cloak class="font-medium text-amber-700">Unsaved changes.</span>
                <span x-show="!dirty">Saves service status, customer message and every biller below.</span>
            </p>
            <button type="submit" form="{{ $formId }}" class="inline-flex items-center rounded-lg bg-brand-violet px-5 py-2 text-sm font-semibold text-white hover:bg-brand-violet-deep focus:outline-none focus:ring-2 focus:ring-brand-violet focus:ring-offset-2">Save changes</button>
        </div>

        {{-- CONFIGURATION HISTORY --}}
        <section class="rounded-xl border border-gray-200 bg-white p-4 sm:p-5" aria-labelledby="hist-h">
            <h2 id="hist-h" class="text-base font-semibold text-gray-900">Configuration history</h2>
            @if (count($audits) === 0)
                <p class="mt-2 text-sm text-gray-500">No changes yet.</p>
            @else
                <ol class="mt-3 space-y-0">
                    @foreach ($audits as $i => $a)
                        <li class="relative border-l border-gray-200 pb-4 pl-4 last:pb-0" @if ($i >= 6) x-show="showAllHistory" x-cloak @endif>
                            <span class="absolute -left-[5px] top-1.5 h-2.5 w-2.5 rounded-full bg-brand-violet-soft ring-2 ring-brand-violet" aria-hidden="true"></span>
                            <p class="text-xs text-gray-500">{{ $a['when']?->format('d M Y · H:i') }}</p>
                            <p class="text-sm font-medium text-gray-900">{{ $a['title'] }}</p>
                            @foreach ($a['lines'] as $line)
                                <p class="text-sm text-gray-600">{{ $line }}</p>
                            @endforeach
                            <p class="text-xs text-gray-500">by {{ $a['who'] }}</p>
                            <details class="mt-1 text-xs">
                                <summary class="cursor-pointer text-gray-500 hover:text-gray-700">Details</summary>
                                <pre class="mt-1 overflow-x-auto rounded bg-gray-50 p-2 text-[11px] text-gray-700">{{ $a['raw'] }}</pre>
                            </details>
                        </li>
                    @endforeach
                </ol>
                @if (count($audits) > 6)
                    <button type="button" @click="showAllHistory = !showAllHistory" class="mt-3 text-sm font-medium text-brand-violet-deep hover:underline focus:outline-none focus:ring-2 focus:ring-brand-violet"
                            x-text="showAllHistory ? 'Show recent only' : 'View all ({{ count($audits) }})'">View all ({{ count($audits) }})</button>
                @endif
            @endif
        </section>
    </div>
</x-admin-layout>
@endsection
