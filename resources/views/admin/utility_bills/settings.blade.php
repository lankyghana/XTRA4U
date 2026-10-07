@extends('layouts.admin')

@section('content')
<x-admin-layout title="Utility Bills Settings" subtitle="Service status, billers and vendor commission" active="utility-bills-settings">
    <div class="space-y-6 max-w-5xl">
        @if (session('success'))
            <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg" role="alert">
                <p class="font-medium">Please fix the following:</p>
                <ul class="list-disc ml-5 text-sm">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        {{-- Provider health (no secrets) --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h2 class="text-base font-semibold text-gray-900 mb-3">Provider health &middot; KiNG FLEXY GH</h2>
            <dl class="grid grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
                <div><dt class="text-gray-500">API key</dt><dd class="mt-1"><x-admin.status :status="$health['configured'] ? 'enabled' : 'failed'" :label="$health['configured'] ? 'Configured' : 'Missing / invalid'" /></dd></div>
                <div><dt class="text-gray-500">Biller catalog</dt><dd class="mt-1"><x-admin.status :status="$health['catalog_available'] ? 'healthy' : ($health['catalog_stale'] ? 'degraded' : 'failed')" :label="$health['catalog_available'] ? 'Live' : ($health['catalog_stale'] ? 'Stale copy' : 'Unavailable')" /></dd></div>
                <div><dt class="text-gray-500">Last catalog success</dt><dd class="mt-1 font-medium text-gray-900">{{ $health['last_catalog_success']?->diffForHumans() ?? 'Never' }}</dd></div>
                <div><dt class="text-gray-500">Needs attention / in flight</dt><dd class="mt-1 font-medium text-gray-900">
                    <a class="{{ $health['attention'] ? 'text-red-700 underline' : '' }}" href="{{ route('admin.utility-bill-sales.index', ['fulfillment' => 'attention']) }}">{{ $health['attention'] }}</a> / {{ $health['in_flight'] }}</dd></div>
            </dl>
            @if ($limits['min'] || $limits['max'])
                <p class="mt-3 text-xs text-gray-500">Provider payment limits: GHS {{ $limits['min'] ?? '—' }} &ndash; {{ $limits['max'] ?? '—' }} (set by the provider, shown for reference).</p>
            @endif
            @if ($health['recent_errors']->isNotEmpty())
                <details class="mt-3 text-sm"><summary class="cursor-pointer text-gray-700">Recent provider issues</summary>
                    <ul class="mt-2 space-y-1 text-gray-600">
                        @foreach ($health['recent_errors'] as $ev)
                            <li>{{ $ev->created_at?->format('d M H:i') }} &middot; {{ $ev->order?->public_ref }} &middot; {{ $ev->kind }} {{ $ev->detail ? '— '.$ev->detail : '' }}</li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </div>

        <form method="POST" action="{{ route('admin.utility-bills.settings.update') }}" class="space-y-6">
            @csrf
            @method('PUT')

            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <h2 class="text-base font-semibold text-gray-900 mb-1">Service status</h2>
                <p class="text-sm text-gray-500 mb-3">Turning the service off stops <strong>new</strong> sales only. Paid orders keep being fulfilled, synced and recovered.</p>
                <input type="hidden" name="enabled" value="0">
                <label class="inline-flex items-center gap-2 text-sm font-medium text-gray-800">
                    <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $enabled)) class="rounded border-gray-300"> Utility Bills enabled for new sales
                </label>
                <label class="block mt-4 text-sm font-medium text-gray-700">Maintenance message (optional)
                    <input type="text" name="maintenance_message" maxlength="255" value="{{ old('maintenance_message', $message) }}" class="mt-1 w-full rounded-md border-gray-300 shadow-sm" placeholder="Shown to customers while the service is off">
                </label>
            </div>

            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <h2 class="text-base font-semibold text-gray-900 mb-1">Billers &amp; vendor commission</h2>
                <p class="text-sm text-gray-500 mb-4">A biller is sold only when the provider reports it enabled <em>and</em> you enable it here. Commission is what a storefront vendor earns on a <strong>completed</strong> bill, calculated on the bill face value. It is frozen on each order at creation; changes affect new orders only. This is separate from any commission the provider pays XTRA4U, which is a variable share of KiNG FLEXY's own commission, paid only when a bill completes: <strong>it is not guaranteed profit</strong>, and you can configure vendor commission above it (nothing blocks that; it is your business decision).</p>

                <div class="space-y-4">
                    @forelse ($rows as $row)
                        @php $k = $row['key']; @endphp
                        <fieldset class="rounded-lg border border-gray-200 p-4">
                            <legend class="px-1 text-sm font-semibold text-gray-900">{{ $row['label'] }} <span class="font-normal text-gray-400">({{ $k }})</span></legend>
                            <div class="flex flex-wrap items-center gap-2 mb-3">
                                <x-admin.status :status="$row['provider_enabled'] ? 'enabled' : 'disabled'" :label="$row['provider_enabled'] ? 'Provider: available' : ($row['listed'] ? 'Provider: disabled' : 'Provider: not listed')" />
                                @if (! $row['provider_enabled'])<span class="text-xs text-amber-700">Cannot be sold until the provider enables it.</span>@endif
                            </div>
                            <input type="hidden" name="billers[{{ $k }}][is_enabled]" value="0">
                            <label class="inline-flex items-center gap-2 text-sm text-gray-800 mb-3">
                                <input type="checkbox" name="billers[{{ $k }}][is_enabled]" value="1" @checked(old("billers.$k.is_enabled", $row['is_enabled'])) class="rounded border-gray-300"> Enabled by XTRA4U
                            </label>
                            @php $eco = $row['economics'] ?? ['n' => 0, 'warn' => false]; @endphp
                            <div class="mb-3 rounded-md border px-3 py-2 text-xs {{ $eco['warn'] ? 'border-amber-300 bg-amber-50 text-amber-900' : 'border-gray-200 bg-gray-50 text-gray-600' }}">
                                @if ($eco['n'] === 0)
                                    Provider economics unknown: no completed {{ $row['label'] }} orders with a provider commission yet. Vendor commission is paid from XTRA4U's own funds and is not tied to what KiNG FLEXY pays XTRA4U.
                                @else
                                    Observed provider commission to XTRA4U: about <strong>{{ $eco['observed_pct'] }}%</strong> of bill value (avg GHS {{ $eco['observed_avg'] }} per order, {{ $eco['n'] }} completed {{ \Illuminate\Support\Str::plural('order', $eco['n']) }}). This is an observation, not a guarantee.
                                    @if ($eco['warn'])
                                        <strong class="block mt-1">Warning: the configured vendor commission is higher than this, so each sale could cost XTRA4U more than the provider pays it.</strong>
                                    @endif
                                @endif
                            </div>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <label class="block text-sm font-medium text-gray-700">Vendor commission type
                                    <select name="billers[{{ $k }}][commission_type]" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                                        <option value="percentage" @selected(old("billers.$k.commission_type", $row['commission_type']) === 'percentage')>Percentage (%)</option>
                                        <option value="fixed" @selected(old("billers.$k.commission_type", $row['commission_type']) === 'fixed')>Fixed amount (GHS)</option>
                                    </select>
                                </label>
                                <label class="block text-sm font-medium text-gray-700">Value
                                    <input type="text" inputmode="decimal" name="billers[{{ $k }}][commission_value]" value="{{ old("billers.$k.commission_value", $row['commission_value']) }}" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                                    @error("billers.$k.commission_value")<span class="text-xs text-red-700">{{ $message }}</span>@enderror
                                </label>
                            </div>
                        </fieldset>
                    @empty
                        <p class="text-sm text-gray-500">No billers available yet. Check the provider API key and try again.</p>
                    @endforelse
                </div>
            </div>

            <button type="submit" class="inline-flex items-center px-5 py-2.5 bg-brand-violet text-white rounded-lg text-sm font-semibold hover:bg-brand-violet-deep">Save configuration</button>
        </form>

        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h2 class="text-base font-semibold text-gray-900 mb-3">Configuration history</h2>
            <ul class="divide-y divide-gray-100 text-sm">
                @forelse ($audits as $a)
                    <li class="py-2">
                        <span class="text-gray-500">{{ $a->created_at?->format('d M Y H:i') }}</span> &middot;
                        <span class="font-medium">{{ $a->admin_email ?? 'admin #'.$a->admin_id }}</span> &middot;
                        {{ $a->scope === 'global' ? 'Service' : $a->biller_key }}:
                        <code class="text-xs">{{ json_encode($a->old_values) }}</code> &rarr; <code class="text-xs">{{ json_encode($a->new_values) }}</code>
                    </li>
                @empty
                    <li class="py-2 text-gray-500">No changes yet.</li>
                @endforelse
            </ul>
        </div>
    </div>
</x-admin-layout>
@endsection
