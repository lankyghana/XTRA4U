@extends('layouts.vendor')

@section('title', 'Vendor Dashboard - XTRA4U')
@section('description', 'Manage your vendor account and track your performance')

@section('content')
<x-vendor-layout :vendor="$vendor" title="Dashboard" subtitle="Manage your vendor account and track your performance" active="dashboard">
    <x-slot name="actions">
        @if($vendor->vendor_code)
        <div x-data="{ copied: false }">
            <button 
                @click="navigator.clipboard.writeText('{{ route('storefront.vendor', ['vendor' => $vendor->vendor_code]) }}'); copied = true; setTimeout(() => copied = false, 2000)"
                class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold rounded-full shadow-md transition-all duration-300 transform hover:scale-105"
                :class="copied ? 'bg-green-500 text-white shadow-green-200' : 'bg-gradient-to-r from-brand-deep-blue to-brand-bright-blue text-white hover:shadow-lg hover:shadow-blue-200'"
            >
                <svg x-show="!copied" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                </svg>
                <svg x-show="copied" x-cloak class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                <span x-text="copied ? 'Link Copied!' : 'Share Store Link'"></span>
            </button>
        </div>
        <div class="ml-2">
            <a href="{{ route('vendor.quick-buy.show') }}" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold rounded-full bg-green-600 text-white hover:bg-green-700">Quick Buy</a>
        </div>
        <div class="ml-2">
            <a href="{{ route('storefront.result-checkers', ['vendor' => $vendor->vendor_code]) }}" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold rounded-full bg-cyan-600 text-white hover:bg-cyan-700">Result Checkers</a>
        </div>
        @endif
    </x-slot>

    <!-- Sales Overview Section -->
    <div x-data="salesFilter()" class="mb-8">
        <!-- Section Header with Filter -->
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 mb-4">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h2 class="text-lg font-bold text-gray-900">Sales Overview</h2>
                    <p class="text-sm text-gray-500">Track your revenue and earnings</p>
                </div>
                <div class="flex items-center gap-1 p-1 bg-gray-100 rounded-xl">
                    <button @click="setFilter('today')" :class="activeFilter === 'today' ? 'bg-white text-brand-deep-blue shadow-sm' : 'text-gray-600 hover:text-gray-900'" class="px-3 py-1.5 text-xs font-semibold rounded-lg transition-all duration-200">
                        Today
                    </button>
                    <button @click="setFilter('yesterday')" :class="activeFilter === 'yesterday' ? 'bg-white text-brand-deep-blue shadow-sm' : 'text-gray-600 hover:text-gray-900'" class="px-3 py-1.5 text-xs font-semibold rounded-lg transition-all duration-200">
                        Yesterday
                    </button>
                    <button @click="setFilter('this_week')" :class="activeFilter === 'this_week' ? 'bg-white text-brand-deep-blue shadow-sm' : 'text-gray-600 hover:text-gray-900'" class="px-3 py-1.5 text-xs font-semibold rounded-lg transition-all duration-200 hidden sm:block">
                        Week
                    </button>
                    <button @click="setFilter('this_month')" :class="activeFilter === 'this_month' ? 'bg-white text-brand-deep-blue shadow-sm' : 'text-gray-600 hover:text-gray-900'" class="px-3 py-1.5 text-xs font-semibold rounded-lg transition-all duration-200">
                        Month
                    </button>
                    <button @click="setFilter('all_time')" :class="activeFilter === 'all_time' ? 'bg-white text-brand-deep-blue shadow-sm' : 'text-gray-600 hover:text-gray-900'" class="px-3 py-1.5 text-xs font-semibold rounded-lg transition-all duration-200">
                        All
                    </button>
                </div>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 gap-4 md:grid-cols-1">
            <!-- Total Earnings Card -->
            <div class="relative overflow-hidden bg-gradient-to-br from-brand-deep-blue to-brand-bright-blue rounded-2xl shadow-lg group">
                <div class="absolute inset-0 bg-white/5 opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
                <div class="absolute -right-8 -top-8 w-32 h-32 bg-white/10 rounded-full blur-2xl"></div>
                <div class="absolute -left-4 -bottom-4 w-24 h-24 bg-white/10 rounded-full blur-xl"></div>
                <div class="relative p-6">
                    <div class="flex items-start justify-between">
                        <div class="flex-1">
                            <div class="flex items-center gap-2 mb-1">
                                <span class="text-blue-100 text-sm font-medium">Total Earnings (Net)</span>
                                <span class="px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider bg-white/20 text-white rounded-full" x-text="filterLabel"></span>
                            </div>
                            <div class="mt-3">
                                <span x-show="loading" class="inline-block w-8 h-8 border-3 border-white/30 border-t-white rounded-full animate-spin"></span>
                                <div x-show="!loading" class="flex items-baseline gap-1">
                                    <span class="text-white/70 text-lg font-medium">GHS</span>
                                    <span class="text-3xl font-bold text-white tracking-tight" x-text="earnings"></span>
                                </div>
                            </div>
                            <p class="mt-2 text-blue-100/80 text-xs">1% Xtra4u + 1% payment fee deducted.</p>
                        </div>
                        <div class="w-14 h-14 bg-white/20 backdrop-blur-sm rounded-2xl flex items-center justify-center ring-1 ring-white/30">
                            <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                            </svg>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 mb-8 lg:grid-cols-3">
        <div class="lg:col-span-2 bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="bg-gradient-to-r from-purple-50 to-blue-50 px-6 py-4 border-b border-gray-100">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-gradient-to-br from-purple-500 to-blue-500 rounded-xl flex items-center justify-center">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-gray-900">Wallet & Withdrawals</h3>
                        <p class="text-xs text-gray-500">A 1% Xtra4u fee and a 1% payment provider fee are automatically applied.</p>
                    </div>
                </div>
            </div>
            <div class="px-6 py-6">
                @if (session('status'))
                    <div class="mb-4 bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg">
                        {{ session('status') }}
                    </div>
                @endif
                
                <form method="POST" action="{{ route('vendor.withdrawals.store') }}" class="space-y-4">
                    @csrf
                    <div>
                        <label for="withdraw_amount" class="block text-sm font-medium text-gray-700 mb-2">Withdrawal Amount</label>
                        <input
                            type="number"
                            step="0.01"
                            min="1"
                            max="{{ $withdrawableBalance }}"
                            name="withdraw_amount"
                            id="withdraw_amount"
                            value="{{ old('withdraw_amount') }}"
                            class="mt-1 block w-full rounded-lg border border-gray-300 shadow-sm focus:border-brand-bright-blue focus:ring-brand-bright-blue px-4 py-3"
                            placeholder="e.g. 120.00"
                            {{ $withdrawableBalance <= 0 ? 'disabled' : '' }}
                        >
                        @error('withdraw_amount')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="momo_number" class="block text-sm font-medium text-gray-700 mb-2">Mobile Money Number <span class="text-red-500">*</span></label>
                        <input
                            type="tel"
                            name="momo_number"
                            id="momo_number"
                            value="{{ old('momo_number') }}"
                            class="mt-1 block w-full rounded-lg border border-gray-300 shadow-sm focus:border-brand-bright-blue focus:ring-brand-bright-blue px-4 py-3"
                            placeholder="0241234567"
                            pattern="0[235][0-9]{8}"
                            maxlength="10"
                            required
                            {{ $withdrawableBalance <= 0 ? 'disabled' : '' }}
                        >
                            <input type="hidden" name="momo_account_name" id="momo_account_name" value="{{ old('momo_account_name') }}">
                            <input type="hidden" name="momo_account_type" id="momo_account_type" value="{{ old('momo_account_type', 'subscriber') }}">
                            <div class="mt-2 text-sm" id="momo_name_status" aria-live="polite"></div>
                        @error('momo_number')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Network <span class="text-red-500">*</span></label>
                        <div class="grid grid-cols-3 gap-2">
                            @php
                                $momoNetworks = config('momo.withdrawal_networks', []);
                            @endphp
                            @foreach ($momoNetworks as $networkValue => $network)
                                <label class="relative cursor-pointer">
                                    <input
                                        type="radio"
                                        name="momo_network"
                                        value="{{ $networkValue }}"
                                        class="peer sr-only"
                                        {{ old('momo_network') === $networkValue ? 'checked' : '' }}
                                        {{ $withdrawableBalance <= 0 ? 'disabled' : '' }}
                                        {{ $loop->first ? 'required' : '' }}
                                    >
                                    <div class="flex flex-col items-center justify-center p-3 rounded-lg border-2 border-gray-200 bg-white {{ $network['radio']['peer_checked_border'] ?? '' }} {{ $network['radio']['peer_checked_bg'] ?? '' }} hover:bg-gray-50 transition-all">
                                        <div class="w-8 h-8 rounded-full {{ $network['radio']['badge_bg'] ?? 'bg-gray-200' }} flex items-center justify-center mb-1">
                                            <span class="text-xs font-bold {{ $network['radio']['badge_text'] ?? 'text-gray-700' }}">{{ $network['radio']['badge_label'] ?? '?' }}</span>
                                        </div>
                                        <span class="text-xs font-medium text-gray-700">{{ $network['label'] ?? $networkValue }}</span>
                                    </div>
                                </label>
                            @endforeach
                        </div>
                        @error('momo_network')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                        <div>
                            <label for="momo_account_type_select" class="block text-sm font-medium text-gray-700 mb-2">Account Type (optional)</label>
                            <select
                                id="momo_account_type_select"
                                class="mt-1 block w-full rounded-lg border border-gray-300 shadow-sm focus:border-brand-bright-blue focus:ring-brand-bright-blue px-4 py-3"
                                {{ $withdrawableBalance <= 0 ? 'disabled' : '' }}
                            >
                                <option value="subscriber" {{ old('momo_account_type', 'subscriber') === 'subscriber' ? 'selected' : '' }}>Subscriber</option>
                                <option value="merchant" {{ old('momo_account_type') === 'merchant' ? 'selected' : '' }}>Merchant</option>
                            </select>
                            @error('momo_account_type')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                    <div>
                        <label for="notes" class="block text-sm font-medium text-gray-700 mb-2">Notes (optional)</label>
                        <textarea
                            id="notes"
                            name="notes"
                            rows="3"
                            class="mt-1 block w-full rounded-lg border border-gray-300 shadow-sm focus:border-brand-bright-blue focus:ring-brand-bright-blue px-4 py-3"
                            placeholder="Add payout instructions or bank details..."
                        >{{ old('notes') }}</textarea>
                    </div>

                    <x-button type="submit" variant="primary" class="w-full justify-center" :disabled="$withdrawableBalance <= 0">
                        Request Withdrawal
                    </x-button>
                    <p class="text-xs text-gray-500">Maximum withdrawable today: <strong>GHS {{ number_format($withdrawableBalance, 2) }}</strong></p>
                </form>
            </div>
        </div>

        <div class="bg-gradient-to-br from-gray-50 to-slate-100 rounded-xl shadow-lg border border-gray-200">
            <div class="px-6 py-6">
                <h3 class="text-lg leading-6 font-bold text-gray-900 mb-4">Recent Wallet Activity</h3>
                <div class="space-y-3">
                    @forelse ($recentWithdrawals as $withdrawal)
                        <div class="p-4 rounded-lg bg-white border border-gray-200 hover:shadow-md transition-shadow duration-200">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-semibold text-gray-900">GHS {{ number_format($withdrawal->amount, 2) }}</p>
                                    <p class="text-xs text-gray-500">{{ $withdrawal->created_at?->diffForHumans() }}</p>
                                </div>
                                    <script>
                                        (function () {
                                            const withdrawalStoreUrl = "{{ route('vendor.withdrawals.store') }}";
                                            const nameQueryUrl = "{{ route('vendor.withdrawals.name-query') }}";
                                            const form = document.querySelector(`form[action="${withdrawalStoreUrl}"]`);
                                            if (!form) return;

                                            const numberInput = form.querySelector('#momo_number');
                                            const nameHidden = form.querySelector('#momo_account_name');
                                            const typeHidden = form.querySelector('#momo_account_type');
                                            const typeSelect = form.querySelector('#momo_account_type_select');
                                            const statusEl = form.querySelector('#momo_name_status');
                                            const networkInputs = Array.from(form.querySelectorAll('input[name="momo_network"]'));

                                            if (!numberInput || !nameHidden || !typeHidden || !typeSelect || !statusEl) return;

                                            const setStatus = (text, variant) => {
                                                const cls = variant === 'ok'
                                                    ? 'text-green-700'
                                                    : variant === 'loading'
                                                        ? 'text-gray-600'
                                                        : 'text-red-600';
                                                statusEl.className = 'text-sm ' + cls;
                                                statusEl.textContent = text;
                                            };

                                            const validNumber = (value) => /^0[235][0-9]{8}$/.test((value || '').trim());
                                            let inFlight = null;

                                            const lookup = async () => {
                                                const momoNumber = (numberInput.value || '').trim();
                                                const selectedNetwork = networkInputs.find(i => i.checked)?.value;

                                                if (!validNumber(momoNumber)) {
                                                    nameHidden.value = '';
                                                    statusEl.textContent = '';
                                                    return;
                                                }

                                                if (!selectedNetwork) {
                                                    nameHidden.value = '';
                                                    setStatus('Select a network to verify account name.', 'loading');
                                                    return;
                                                }

                                                if (inFlight) {
                                                    try { inFlight.abort(); } catch (e) {}
                                                }
                                                inFlight = new AbortController();

                                                setStatus('Verifying account name...', 'loading');

                                                try {
                                                    const response = await fetch(nameQueryUrl, {
                                                        method: 'POST',
                                                        headers: {
                                                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                                            'Content-Type': 'application/json',
                                                            'Accept': 'application/json'
                                                        },
                                                        body: JSON.stringify({ momo_number: momoNumber }),
                                                        signal: inFlight.signal
                                                    });

                                                    const result = await response.json();

                                                    if (result && result.success && result.name) {
                                                        nameHidden.value = result.name;
                                                        setStatus('Account Name: ' + result.name, 'ok');
                                                        return;
                                                    }

                                                    nameHidden.value = '';
                                                    setStatus(result?.message || 'Unable to verify account name.', 'error');
                                                } catch (e) {
                                                    if (e?.name === 'AbortError') return;
                                                    nameHidden.value = '';
                                                    setStatus('Unable to verify account name right now.', 'error');
                                                }
                                            };

                                            typeSelect.addEventListener('change', () => {
                                                typeHidden.value = typeSelect.value;
                                            });
                                            typeHidden.value = typeSelect.value;

                                            numberInput.addEventListener('blur', lookup);
                                            numberInput.addEventListener('change', lookup);
                                            networkInputs.forEach(i => i.addEventListener('change', lookup));

                                            if ((nameHidden.value || '').trim() !== '') {
                                                setStatus('Account Name: ' + nameHidden.value, 'ok');
                                            }
                                        })();
                                    </script>
                                @if ($withdrawal->status === 'approved')
                                    <x-badge variant="completed">Approved</x-badge>
                                @elseif ($withdrawal->status === 'rejected')
                                    <x-badge variant="warning">Rejected</x-badge>
                                @elseif ($withdrawal->status === 'cancelled')
                                    <x-badge variant="warning">Cancelled</x-badge>
                                @else
                                    <x-badge variant="pending">Pending</x-badge>
                                @endif
                            </div>
                            <p class="text-xs text-gray-500 mt-1">Ref: {{ $withdrawal->reference }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">No withdrawal history yet.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div class="bg-gradient-to-br from-white to-gray-50 rounded-xl shadow-lg border border-gray-200">
            <div class="px-6 py-6">
                <h3 class="text-lg leading-6 font-bold text-gray-900 mb-4">Recent Orders</h3>
                <div class="overflow-hidden rounded-lg border border-gray-200">
                    <x-table :headers="['Order ID', 'Recipient', 'Amount', 'Status', 'Placed']">
                        @forelse ($orders->take(5) as $order)
                            @php
                                $isAfaOrder = $order instanceof \App\Models\AfaRegistration;
                                $displayReference = $isAfaOrder
                                    ? ($order->reference ?: ('AFA-' . $order->id))
                                    : ('#' . $order->id);
                                $recipientPhone = $isAfaOrder ? $order->phone_number : $order->recipient_phone_number;
                                $displayAmount = $isAfaOrder ? (float) $order->amount : (float) $order->amount_paid;
                                $rawStatus = strtolower((string) $order->status);
                                $statusVariant = in_array($rawStatus, ['completed', 'approved'], true)
                                    ? 'completed'
                                    : (in_array($rawStatus, ['rejected', 'cancelled', 'failed'], true) ? 'warning' : 'pending');
                                $statusLabel = $isAfaOrder ? ucfirst((string) $order->status) : (string) $order->status;
                            @endphp
                            <tr class="hover:bg-gray-50 transition-colors duration-150">
                                <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $displayReference }}</td>
                                <td class="px-6 py-4 text-sm text-gray-900">{{ $recipientPhone }}</td>
                                <td class="px-6 py-4 text-sm text-gray-900">GHS {{ number_format($displayAmount, 2) }}</td>
                                <td class="px-6 py-4 text-sm">
                                    <x-badge :variant="$statusVariant">{{ $statusLabel }}</x-badge>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-700">{{ $order->created_at?->format('M d, Y g:i A') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-4 text-center text-sm text-gray-500">No orders yet.</td>
                            </tr>
                        @endforelse
                    </x-table>
                </div>
                <div class="mt-4">
                    <x-button href="{{ route('vendor.orders.index') }}" variant="outline" size="sm">
                        View All Orders
                    </x-button>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-br from-brand-deep-blue to-brand-bright-blue rounded-xl shadow-lg overflow-hidden">
            <div class="px-6 py-6">
                <h3 class="text-lg leading-6 font-bold text-white mb-4">Quick Actions</h3>
                <div class="space-y-3">
                    <a href="{{ route('vendor.products.create') }}" class="flex items-center justify-center w-full bg-white text-brand-deep-blue hover:bg-gray-50 font-semibold py-3 px-4 rounded-lg transition-all duration-200 hover:scale-105 shadow-md">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                        </svg>
                        Add New Product
                    </a>

                    <a href="{{ route('vendor.orders.index') }}" class="flex items-center justify-center w-full bg-white/10 backdrop-blur-sm text-white hover:bg-white/20 font-semibold py-3 px-4 rounded-lg transition-all duration-200 hover:scale-105 border border-white/20">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                        </svg>
                        Process Orders
                    </a>

                    <a href="{{ route('vendor.analytics.index') }}" class="flex items-center justify-center w-full bg-white/10 backdrop-blur-sm text-white hover:bg-white/20 font-semibold py-3 px-4 rounded-lg transition-all duration-200 hover:scale-105 border border-white/20">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                        </svg>
                        View Analytics
                    </a>

                    @if (empty($vendor->affiliate_vendor_id))
                        <a href="{{ route('vendor.settings.external-fulfillment') }}" class="flex items-center justify-center w-full bg-white/10 backdrop-blur-sm text-white hover:bg-white/20 font-semibold py-3 px-4 rounded-lg transition-all duration-200 hover:scale-105 border border-white/20">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                            External Fulfillment
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Results Checker Section -->
    <div class="mt-8">
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="bg-gradient-to-r from-cyan-50 to-blue-50 px-6 py-4 border-b border-gray-100">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-gradient-to-br from-cyan-500 to-blue-500 rounded-xl flex items-center justify-center">
                        <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M7 3a1 1 0 000 2h6a1 1 0 000-2H7zM4 7a1 1 0 011-1h10a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1V7z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-gray-900">Results Checker Activity</h3>
                        <p class="text-xs text-gray-500">Today's results checker orders and performance</p>
                    </div>
                </div>
            </div>
            <div class="px-6 py-6">
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <!-- Orders Today -->
                    <div class="bg-gradient-to-br from-cyan-50 to-blue-50 rounded-lg p-4 border border-cyan-100">
                        <div class="flex flex-col gap-2">
                            <p class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Orders Today</p>
                            <p class="text-2xl font-bold text-gray-900">{{ $resultCheckerOrdersToday }}</p>
                        </div>
                    </div>

                    <!-- Revenue Today -->
                    <div class="bg-gradient-to-br from-emerald-50 to-green-50 rounded-lg p-4 border border-emerald-100">
                        <div class="flex flex-col gap-2">
                            <p class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Revenue Today</p>
                            <p class="text-2xl font-bold text-gray-900">GHS {{ number_format($resultCheckerRevenueToday, 2) }}</p>
                        </div>
                    </div>

                    <!-- Completed Orders -->
                    <div class="bg-gradient-to-br from-violet-50 to-purple-50 rounded-lg p-4 border border-violet-100">
                        <div class="flex flex-col gap-2">
                            <p class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Completed</p>
                            <p class="text-2xl font-bold text-gray-900">{{ $resultCheckerCompletedToday }}</p>
                        </div>
                    </div>

                    <!-- Pending Stock -->
                    <div class="bg-gradient-to-br from-orange-50 to-amber-50 rounded-lg p-4 border border-orange-100">
                        <div class="flex flex-col gap-2">
                            <p class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Pending Stock</p>
                            <p class="text-2xl font-bold text-gray-900">{{ $resultCheckerPendingStock }}</p>
                        </div>
                    </div>
                </div>

                <div class="mt-4">
                    <a href="{{ route('storefront.result-checkers', ['vendor' => $vendor->vendor_code]) }}" class="inline-flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-cyan-600 to-blue-600 text-white rounded-lg hover:shadow-lg transition-all duration-200 text-sm font-semibold">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                        </svg>
                        Manage Results Checkers
                    </a>
                </div>
            </div>
        </div>
    </div>
</x-vendor-layout>
@endsection

@push('scripts')
<script>
function salesFilter() {
    return {
        activeFilter: '{{ $filter ?? "today" }}',
        sales: '{{ number_format($totalSales, 2) }}',
        earnings: '{{ number_format($totalEarnings, 2) }}',
        loading: false,
        
        get filterLabel() {
            const labels = {
                'today': 'Today',
                'yesterday': 'Yesterday',
                'this_week': 'This Week',
                'this_month': 'This Month',
                'all_time': 'All Time'
            };
            return labels[this.activeFilter] || 'Today';
        },
        
        async setFilter(filter) {
            if (this.activeFilter === filter) return;
            
            this.activeFilter = filter;
            this.loading = true;
            
            try {
                const response = await fetch(`{{ route('vendor.dashboard.sales-stats') }}?filter=${filter}`, {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                
                if (response.ok) {
                    const data = await response.json();
                    this.sales = data.sales;
                    this.earnings = data.earnings;
                }
            } catch (error) {
                console.error('Failed to fetch stats:', error);
            } finally {
                this.loading = false;
            }
        }
    }
}
</script>
@endpush
