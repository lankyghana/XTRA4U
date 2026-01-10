@extends('layouts.vendor')

@section('title', 'Withdrawals - XTRA4U')
@section('description', 'Track all payout requests and submit new vendor withdrawals')

@section('content')
<x-vendor-layout :vendor="$vendor" title="Withdrawals" subtitle="Track payout history and request new disbursements" active="withdrawals">

    <div class="space-y-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <!-- Withdrawable Balance Card -->
            <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl shadow-lg overflow-hidden transform hover:scale-105 transition-transform duration-200">
                <div class="px-6 py-5">
                    <p class="text-sm text-purple-100 font-medium">Withdrawable Balance</p>
                    <p class="text-2xl font-bold text-white mt-2">GHS {{ number_format($withdrawableBalance, 2) }}</p>
                    <p class="text-xs text-purple-100 mt-2">Available wallet balance for withdrawals.</p>
                </div>
            </div>

            <!-- Processing Requests Card -->
            <div class="bg-gradient-to-br from-yellow-500 to-yellow-600 rounded-xl shadow-lg overflow-hidden transform hover:scale-105 transition-transform duration-200">
                <div class="px-6 py-5">
                    <p class="text-sm text-yellow-100 font-medium">Processing Requests</p>
                    <p class="text-2xl font-bold text-white mt-2">GHS {{ number_format($pendingTotal, 2) }}</p>
                    <p class="text-xs text-yellow-100 mt-2">Being processed automatically.</p>
                </div>
            </div>

            <!-- Approved To Date Card -->
            <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-xl shadow-lg overflow-hidden transform hover:scale-105 transition-transform duration-200">
                <div class="px-6 py-5">
                    <p class="text-sm text-green-100 font-medium">Approved To Date</p>
                    <p class="text-2xl font-bold text-white mt-2">GHS {{ number_format($approvedTotal, 2) }}</p>
                    <p class="text-xs text-green-100 mt-2">Total paid out successfully.</p>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-br from-purple-50 to-blue-50 rounded-xl shadow-lg border border-purple-100">
            <div class="px-6 py-6">
                <h2 class="text-lg font-bold text-gray-900 mb-4">Request a Withdrawal</h2>

                @if (session('status'))
                    <div class="mb-4 bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg">
                        {{ session('status') }}
                    </div>
                @endif

                <form method="POST" action="{{ route('vendor.withdrawals.store') }}" class="space-y-6">
                    @csrf
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <!-- Mobile Money Number -->
                        <div>
                            <label for="momo_number" class="block text-sm font-medium text-gray-700 mb-2">
                                Mobile Money Number <span class="text-red-500">*</span>
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                                    </svg>
                                </div>
                                <input
                                    type="tel"
                                    name="momo_number"
                                    id="momo_number"
                                    value="{{ old('momo_number') }}"
                                    class="block w-full pl-10 pr-4 py-3 rounded-lg border border-gray-300 shadow-sm focus:border-purple-500 focus:ring-purple-500"
                                    placeholder="0241234567"
                                    pattern="0[235][0-9]{8}"
                                    maxlength="10"
                                    required
                                    {{ $withdrawableBalance <= 0 ? 'disabled' : '' }}
                                >
                            </div>

                            <input type="hidden" name="momo_account_name" id="momo_account_name" value="{{ old('momo_account_name') }}">
                            <input type="hidden" name="momo_account_type" id="momo_account_type" value="{{ old('momo_account_type', 'subscriber') }}">

                            <div class="mt-2 text-sm" id="momo_name_status" aria-live="polite"></div>
                            @error('momo_number')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Network Selection -->
                        <div>
                            <div class="block text-sm font-medium text-gray-700 mb-2">
                                Network <span class="text-red-500">*</span>
                            </div>
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

                        <!-- Account Type (optional) -->
                        <div>
                            <label for="momo_account_type_select" class="block text-sm font-medium text-gray-700 mb-2">Account Type (optional)</label>
                            <select
                                id="momo_account_type_select"
                                class="block w-full px-4 py-3 rounded-lg border border-gray-300 shadow-sm focus:border-purple-500 focus:ring-purple-500"
                                {{ $withdrawableBalance <= 0 ? 'disabled' : '' }}
                            >
                                <option value="subscriber" {{ old('momo_account_type', 'subscriber') === 'subscriber' ? 'selected' : '' }}>Subscriber</option>
                                <option value="merchant" {{ old('momo_account_type') === 'merchant' ? 'selected' : '' }}>Merchant</option>
                            </select>
                            @error('momo_account_type')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Withdrawal Amount -->
                        <div>
                            <label for="withdraw_amount" class="block text-sm font-medium text-gray-700 mb-2">
                                Amount (GHS) <span class="text-red-500">*</span>
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <span class="text-gray-500 font-medium">₵</span>
                                </div>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="1"
                                    max="{{ $withdrawableBalance }}"
                                    name="withdraw_amount"
                                    id="withdraw_amount"
                                    value="{{ old('withdraw_amount') }}"
                                    class="block w-full pl-8 pr-4 py-3 rounded-lg border border-gray-300 shadow-sm focus:border-purple-500 focus:ring-purple-500"
                                    placeholder="250.00"
                                    required
                                    {{ $withdrawableBalance <= 0 ? 'disabled' : '' }}
                                >
                            </div>
                            @error('withdraw_amount')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>


                    <!-- Notes -->
                    <div>
                        <label for="notes" class="block text-sm font-medium text-gray-700 mb-2">Notes (optional)</label>
                        <textarea
                            id="notes"
                            name="notes"
                            rows="2"
                            class="block w-full rounded-lg border border-gray-300 shadow-sm focus:border-purple-500 focus:ring-purple-500 px-4 py-3"
                            placeholder="Any additional instructions..."
                        >{{ old('notes') }}</textarea>
                        @error('notes')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between pt-2 border-t border-gray-200">
                        <p class="text-sm text-gray-600">
                            Available balance: <strong class="text-purple-600">GHS {{ number_format($withdrawableBalance, 2) }}</strong>
                        </p>
                        <x-button type="submit" variant="primary" class="justify-center" :disabled="$withdrawableBalance <= 0">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            Request Withdrawal
                        </x-button>
                    </div>
                </form>
            </div>
        </div>

        <div class="bg-gradient-to-br from-white to-gray-50 rounded-xl shadow-lg border border-gray-200">
            <div class="px-6 py-6">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h2 class="text-lg font-bold text-gray-900">Withdrawal History</h2>
                        <p class="text-sm text-gray-600">Track every payout request and its status.</p>
                    </div>
                </div>

                <div class="overflow-hidden rounded-lg border border-gray-200">
                    <x-table :headers="['Reference', 'Date', 'MoMo Details', 'Amount', 'Status']">
                        @forelse ($withdrawals as $withdrawal)
                            <tr class="hover:bg-gray-50 transition-colors duration-150">
                                <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $withdrawal->reference }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $withdrawal->created_at?->format('M d, Y • h:i A') }}</td>
                                <td class="px-6 py-4 text-sm">
                                    <div class="flex items-center gap-2">
                                        @php
                                            $network = config('momo.withdrawal_networks.' . ($withdrawal->momo_network ?? ''), null);
                                        @endphp
                                        @if ($network)
                                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full {{ $network['history']['badge_class'] ?? 'bg-gray-200 text-gray-600' }} text-xs font-bold">{{ $network['history']['badge_label'] ?? '?' }}</span>
                                        @endif
                                        <div>
                                            <p class="font-medium text-gray-900">{{ $withdrawal->momo_number ?? 'N/A' }}</p>
                                            <p class="text-xs text-gray-500">{{ $withdrawal->momo_network ?? 'N/A' }}@if(!empty($withdrawal->momo_account_name)) • {{ $withdrawal->momo_account_name }}@endif</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm font-semibold text-gray-900">GHS {{ number_format($withdrawal->amount, 2) }}</td>
                                <td class="px-6 py-4 text-sm">
                                    @if ($withdrawal->status === \App\Models\VendorWithdrawal::STATUS_APPROVED)
                                        <x-badge variant="completed">Approved</x-badge>
                                    @elseif ($withdrawal->status === \App\Models\VendorWithdrawal::STATUS_PROCESSING)
                                        <x-badge variant="processing">Processing</x-badge>
                                    @elseif ($withdrawal->status === \App\Models\VendorWithdrawal::STATUS_FAILED)
                                        <x-badge variant="warning">Failed</x-badge>
                                    @else
                                        <x-badge variant="pending">Unknown</x-badge>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-4 text-center text-sm text-gray-500">No withdrawal activity yet.</td>
                            </tr>
                        @endforelse
                    </x-table>
                </div>

                @if ($withdrawals->hasPages())
                    <div class="mt-4">
                        {{ $withdrawals->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-vendor-layout>

<script>
    (function () {
        const numberInput = document.getElementById('momo_number');
        const nameHidden = document.getElementById('momo_account_name');
        const typeHidden = document.getElementById('momo_account_type');
        const typeSelect = document.getElementById('momo_account_type_select');
        const statusEl = document.getElementById('momo_name_status');
        const networkInputs = Array.from(document.querySelectorAll('input[name="momo_network"]'));

        if (!numberInput || !nameHidden || !typeHidden || !typeSelect || !statusEl) return;

        const setStatus = (text, variant) => {
            const base = 'text-sm';
            const cls = variant === 'ok'
                ? 'text-green-700'
                : variant === 'loading'
                    ? 'text-gray-600'
                    : 'text-red-600';
            statusEl.className = base + ' ' + cls;
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
                const response = await fetch('{{ route('vendor.withdrawals.name-query') }}', {
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

        // If returning to page with old input, show the stored name.
        if ((nameHidden.value || '').trim() !== '') {
            setStatus('Account Name: ' + nameHidden.value, 'ok');
        }
    })();
</script>
@endsection