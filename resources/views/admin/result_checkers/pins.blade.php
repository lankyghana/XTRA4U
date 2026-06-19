@extends('layouts.admin')

@section('content')
<x-admin-layout title="Result Checker PINs" subtitle="Upload and manage PIN inventory" active="result-checkers">
    <div class="space-y-6">

        {{-- Flash Messages --}}
        @if(session('success'))
            <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg">
                {{ session('success') }}
            </div>
        @endif

        @if($errors->any())
            <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg">
                {{ $errors->first() }}
            </div>
        @endif

        {{-- ── Row 1: Base Price + Pricing Tiers ─────────────────────────── --}}
        <div class="grid gap-6 lg:grid-cols-2">

            {{-- Update Base Price --}}
            <div class="bg-white shadow-sm rounded-lg p-6 space-y-4">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Update Base Price</h3>
                    <p class="text-xs text-gray-500 mt-0.5">
                        Sets the default price per PIN when no volume tier applies.
                    </p>
                </div>

                <form action="{{ route('admin.result-checkers.base-price.update') }}" method="POST" class="space-y-4">
                    @csrf
                    @method('PATCH')

                    <div>
                        <label for="bp_service" class="block text-sm font-medium text-gray-700">Service</label>
                        <select id="bp_service" name="network_service_id"
                            class="mt-1 w-full rounded-md border-gray-300 shadow-sm text-sm" required>
                            <option value="">— choose a service —</option>
                            @foreach($services as $svc)
                                <option value="{{ $svc->id }}"
                                    data-price="{{ $svc->base_price }}"
                                    @selected(old('network_service_id') == $svc->id || $selectedService?->id == $svc->id)>
                                    {{ $svc->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="bp_price" class="block text-sm font-medium text-gray-700">
                            Base Price (GHS)
                        </label>
                        <div class="relative mt-1">
                            <span class="absolute inset-y-0 left-3 flex items-center text-gray-400 text-sm pointer-events-none">GHS</span>
                            <input id="bp_price" type="number" name="base_price" step="0.01" min="0"
                                class="w-full pl-12 rounded-md border-gray-300 shadow-sm text-sm"
                                value="{{ old('base_price', $selectedService?->base_price) }}"
                                placeholder="0.00" required>
                        </div>
                    </div>

                    <button type="submit"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-brand-deep-blue text-white rounded-md text-sm font-semibold hover:opacity-90 transition">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                        </svg>
                        Save Base Price
                    </button>
                </form>
            </div>

            {{-- Pricing Tiers --}}
            <div class="bg-white shadow-sm rounded-lg p-6 space-y-4" id="pricing-tiers-panel">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Pricing Tiers</h3>
                    <p class="text-xs text-gray-500 mt-0.5">
                        Volume-based price overrides. The matching tier's price is used instead of the base price.
                    </p>
                </div>

                {{-- Add Tier Form --}}
                <form action="{{ route('admin.result-checker-pricing-tiers.store') }}" method="POST" class="space-y-4">
                    @csrf

                    <div>
                        <label for="tier_service" class="block text-sm font-medium text-gray-700">Service</label>
                        <select id="tier_service" name="network_service_id"
                            class="mt-1 w-full rounded-md border-gray-300 shadow-sm text-sm"
                            onchange="loadTiers(this.value)" required>
                            <option value="">— choose a service —</option>
                            @foreach($services as $svc)
                                <option value="{{ $svc->id }}"
                                    @selected(old('network_service_id') == $svc->id || $selectedService?->id == $svc->id)>
                                    {{ $svc->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Min Qty</label>
                            <input type="number" name="min_quantity" id="tier_min"
                                class="mt-1 w-full rounded-md border-gray-300 shadow-sm text-sm"
                                min="1" placeholder="e.g. 1" value="{{ old('min_quantity') }}" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Max Qty</label>
                            <input type="number" name="max_quantity" id="tier_max"
                                class="mt-1 w-full rounded-md border-gray-300 shadow-sm text-sm"
                                min="1" placeholder="blank = ∞" value="{{ old('max_quantity') }}">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Price (GHS)</label>
                            <input type="number" name="price" step="0.01"
                                class="mt-1 w-full rounded-md border-gray-300 shadow-sm text-sm"
                                min="0" placeholder="0.00" value="{{ old('price') }}" required>
                        </div>
                    </div>

                    <button type="submit"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-green-600 text-white rounded-md text-sm font-semibold hover:bg-green-700 transition">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                        </svg>
                        Add Tier
                    </button>
                </form>

                {{-- Existing Tiers List --}}
                <div id="tiers-list" class="space-y-2 pt-2">
                    @if($selectedService)
                        @forelse($pricingTiers as $tier)
                            <div class="flex items-center justify-between bg-gray-50 border border-gray-200 px-3 py-2 rounded-md">
                                <div class="text-sm">
                                    <span class="font-semibold text-gray-800">
                                        {{ $tier->min_quantity }} – {{ $tier->max_quantity ?? '∞' }}
                                    </span>
                                    <span class="text-gray-400 mx-1">·</span>
                                    <span class="text-gray-700">GHS {{ number_format($tier->price, 2) }}</span>
                                </div>
                                <form action="{{ route('admin.result-checker-pricing-tiers.destroy', $tier) }}" method="POST">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                        class="text-red-500 hover:text-red-700 text-xs font-semibold"
                                        onclick="return confirm('Delete this pricing tier?')">
                                        Remove
                                    </button>
                                </form>
                            </div>
                        @empty
                            <p class="text-sm text-gray-400 italic">No tiers yet — base price applies to all quantities.</p>
                        @endforelse
                    @else
                        <p class="text-sm text-gray-400 italic" id="tiers-placeholder">
                            Select a service above to see its existing tiers.
                        </p>
                    @endif
                </div>
            </div>
        </div>

        {{-- ── Row 2: Bulk Upload PINs ────────────────────────────────────── --}}
        <div class="bg-white shadow-sm rounded-lg p-6 space-y-4">
            <div>
                <h3 class="text-base font-semibold text-gray-900">Bulk Upload PINs</h3>
                <p class="text-xs text-gray-500 mt-0.5">Upload a CSV file or paste rows in <code class="bg-gray-100 px-1 rounded">serial,pin</code> format.</p>
            </div>
            <form action="{{ route('admin.result-checkers.pins.store') }}" method="POST" enctype="multipart/form-data" class="space-y-4">
                @csrf
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Service</label>
                        <select name="service_id" class="mt-1 w-full rounded-md border-gray-300 shadow-sm text-sm" required>
                            <option value="">— choose a service —</option>
                            @foreach($services as $service)
                                <option value="{{ $service->id }}" @selected(old('service_id', $selectedService?->id) == $service->id)>
                                    {{ $service->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">CSV File (serial, pin)</label>
                        <input type="file" name="pins_file" accept=".csv,.txt"
                            class="mt-1 block w-full text-sm text-gray-600 border border-gray-300 rounded-md px-3 py-2 cursor-pointer">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Or Paste CSV Rows</label>
                    <textarea name="pins_text" rows="5"
                        class="mt-1 w-full rounded-md border-gray-300 shadow-sm text-sm font-mono"
                        placeholder="serial,pin&#10;1234567890,9876543210&#10;0987654321,1234567890">{{ old('pins_text') }}</textarea>
                </div>

                <button type="submit"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-brand-deep-blue text-white rounded-md text-sm font-semibold hover:opacity-90 transition">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a2 2 0 002 2h12a2 2 0 002-2v-1M12 12V4m0 0l-3 3m3-3l3 3"/>
                    </svg>
                    Upload PINs
                </button>
            </form>
        </div>

        {{-- ── Row 3: Filters + PIN Table ─────────────────────────────────── --}}
        <div class="bg-white shadow-sm rounded-lg p-6 space-y-4">
            <h3 class="text-base font-semibold text-gray-900">Filter PINs</h3>
            <form method="GET" action="{{ route('admin.result-checkers.pins.index') }}" class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Service</label>
                    <select name="service_id" class="mt-1 w-full rounded-md border-gray-300 shadow-sm text-sm" onchange="this.form.submit()">
                        <option value="">All Services</option>
                        @foreach($services as $service)
                            <option value="{{ $service->id }}" @selected($selectedService?->id == $service->id)>
                                {{ $service->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Status</label>
                    <select name="status" class="mt-1 w-full rounded-md border-gray-300 shadow-sm text-sm">
                        <option value="">All</option>
                        <option value="available" @selected($selectedStatus === 'available')>Unused</option>
                        <option value="sold"      @selected($selectedStatus === 'sold')>Used</option>
                        <option value="reserved"  @selected($selectedStatus === 'reserved')>Reserved</option>
                    </select>
                </div>
                <div class="flex items-end">
                    <button type="submit"
                        class="w-full inline-flex justify-center items-center px-4 py-2 bg-gray-900 text-white rounded-md text-sm font-semibold hover:bg-gray-700 transition">
                        Apply Filters
                    </button>
                </div>
            </form>
        </div>

        @php
            $mask = fn ($value) => strlen((string) $value) <= 4
                ? str_repeat('*', strlen((string) $value))
                : str_repeat('*', max(strlen((string) $value) - 4, 0)) . substr((string) $value, -4);
        @endphp

        <x-table :headers="['Service', 'Serial', 'PIN', 'Status', 'Order', 'Uploaded']">
            @forelse ($pins as $pin)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 text-sm text-gray-900">{{ $pin->service?->name ?? 'N/A' }}</td>
                    <td class="px-6 py-4 text-sm text-gray-900 font-mono">{{ $mask($pin->serial) }}</td>
                    <td class="px-6 py-4 text-sm text-gray-900 font-mono">{{ $mask($pin->pin) }}</td>
                    <td class="px-6 py-4 text-sm">
                        @php
                            $statusColour = match($pin->status) {
                                'available' => 'text-green-700 bg-green-50 border-green-200',
                                'sold'      => 'text-gray-500 bg-gray-50 border-gray-200',
                                default     => 'text-yellow-700 bg-yellow-50 border-yellow-200',
                            };
                        @endphp
                        <span class="px-2 py-0.5 text-xs font-semibold rounded-full border {{ $statusColour }}">
                            {{ $pin->status === 'available' ? 'Unused' : ucfirst($pin->status) }}
                        </span>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-900">
                        {{ $pin->order ? '#' . $pin->order->id : '—' }}
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ $pin->created_at?->format('Y-m-d') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500">No pins found.</td>
                </tr>
            @endforelse
        </x-table>

        @if($pins->hasPages())
            <div class="flex justify-end pt-2">
                {{ $pins->links() }}
            </div>
        @endif

    </div>
</x-admin-layout>

{{-- Auto-fill base price when service is selected in the Base Price form --}}
<script>
    (function () {
        const sel = document.getElementById('bp_service');
        const inp = document.getElementById('bp_price');
        if (!sel || !inp) return;

        sel.addEventListener('change', function () {
            const chosen = this.options[this.selectedIndex];
            const price  = chosen.dataset.price;
            if (price !== undefined && price !== '') {
                inp.value = parseFloat(price).toFixed(2);
            } else {
                inp.value = '';
            }
        });

        // Trigger on load if a service is pre-selected
        if (sel.value) sel.dispatchEvent(new Event('change'));
    })();

    // Reload page filtered to chosen service so tiers list updates
    function loadTiers(serviceId) {
        if (!serviceId) return;
        const url = new URL(window.location.href);
        url.searchParams.set('service_id', serviceId);
        // preserve status filter if present
        window.location.href = url.toString() + '#pricing-tiers-panel';
    }
</script>
@endsection
