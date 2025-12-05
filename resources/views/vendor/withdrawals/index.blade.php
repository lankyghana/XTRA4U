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
                    <p class="text-xs text-purple-100 mt-2">Includes completed earnings minus pending payouts.</p>
                </div>
            </div>

            <!-- Pending Requests Card -->
            <div class="bg-gradient-to-br from-yellow-500 to-yellow-600 rounded-xl shadow-lg overflow-hidden transform hover:scale-105 transition-transform duration-200">
                <div class="px-6 py-5">
                    <p class="text-sm text-yellow-100 font-medium">Pending Requests</p>
                    <p class="text-2xl font-bold text-white mt-2">GHS {{ number_format($pendingTotal, 2) }}</p>
                    <p class="text-xs text-yellow-100 mt-2">Awaiting finance review.</p>
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
                            @error('momo_number')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Network Selection -->
                        <div>
                            <label for="momo_network" class="block text-sm font-medium text-gray-700 mb-2">
                                Network <span class="text-red-500">*</span>
                            </label>
                            <div class="grid grid-cols-3 gap-2">
                                <label class="relative cursor-pointer">
                                    <input type="radio" name="momo_network" value="MTN" class="peer sr-only" {{ old('momo_network') === 'MTN' ? 'checked' : '' }} {{ $withdrawableBalance <= 0 ? 'disabled' : '' }} required>
                                    <div class="flex flex-col items-center justify-center p-3 rounded-lg border-2 border-gray-200 bg-white peer-checked:border-yellow-500 peer-checked:bg-yellow-50 hover:bg-gray-50 transition-all">
                                        <div class="w-8 h-8 rounded-full bg-yellow-400 flex items-center justify-center mb-1">
                                            <span class="text-xs font-bold text-yellow-900">MTN</span>
                                        </div>
                                        <span class="text-xs font-medium text-gray-700">MTN</span>
                                    </div>
                                </label>
                                <label class="relative cursor-pointer">
                                    <input type="radio" name="momo_network" value="TELECEL" class="peer sr-only" {{ old('momo_network') === 'TELECEL' ? 'checked' : '' }} {{ $withdrawableBalance <= 0 ? 'disabled' : '' }}>
                                    <div class="flex flex-col items-center justify-center p-3 rounded-lg border-2 border-gray-200 bg-white peer-checked:border-red-500 peer-checked:bg-red-50 hover:bg-gray-50 transition-all">
                                        <div class="w-8 h-8 rounded-full bg-red-500 flex items-center justify-center mb-1">
                                            <span class="text-xs font-bold text-white">TEL</span>
                                        </div>
                                        <span class="text-xs font-medium text-gray-700">Telecel</span>
                                    </div>
                                </label>
                                <label class="relative cursor-pointer">
                                    <input type="radio" name="momo_network" value="AirtelTigo" class="peer sr-only" {{ old('momo_network') === 'AirtelTigo' ? 'checked' : '' }} {{ $withdrawableBalance <= 0 ? 'disabled' : '' }}>
                                    <div class="flex flex-col items-center justify-center p-3 rounded-lg border-2 border-gray-200 bg-white peer-checked:border-blue-500 peer-checked:bg-blue-50 hover:bg-gray-50 transition-all">
                                        <div class="w-8 h-8 rounded-full bg-gradient-to-r from-red-500 to-blue-500 flex items-center justify-center mb-1">
                                            <span class="text-xs font-bold text-white">AT</span>
                                        </div>
                                        <span class="text-xs font-medium text-gray-700">AirtelTigo</span>
                                    </div>
                                </label>
                            </div>
                            @error('momo_network')
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

                    <!-- Account Name Display -->
                    <div id="account-name-container" class="hidden">
                        <div class="flex items-center gap-3 p-4 rounded-lg bg-green-50 border border-green-200">
                            <div class="flex-shrink-0">
                                <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-green-800">Account Verified</p>
                                <p id="account-name-display" class="text-lg font-bold text-green-900"></p>
                            </div>
                        </div>
                    </div>

                    <!-- Account Lookup Loading -->
                    <div id="account-lookup-loading" class="hidden">
                        <div class="flex items-center gap-3 p-4 rounded-lg bg-blue-50 border border-blue-200">
                            <div class="flex-shrink-0">
                                <svg class="w-6 h-6 text-blue-600 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </div>
                            <p class="text-sm font-medium text-blue-800">Verifying account...</p>
                        </div>
                    </div>

                    <!-- Account Lookup Error -->
                    <div id="account-lookup-error" class="hidden">
                        <div class="flex items-center gap-3 p-4 rounded-lg bg-red-50 border border-red-200">
                            <div class="flex-shrink-0">
                                <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-red-800">Verification Failed</p>
                                <p id="account-error-message" class="text-sm text-red-600"></p>
                            </div>
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
                                        @if($withdrawal->momo_network === 'MTN')
                                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-yellow-400 text-yellow-900 text-xs font-bold">M</span>
                                        @elseif($withdrawal->momo_network === 'TELECEL')
                                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-red-500 text-white text-xs font-bold">T</span>
                                        @elseif($withdrawal->momo_network === 'AirtelTigo')
                                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-gradient-to-r from-red-500 to-blue-500 text-white text-xs font-bold">A</span>
                                        @endif
                                        <div>
                                            <p class="font-medium text-gray-900">{{ $withdrawal->momo_number ?? 'N/A' }}</p>
                                            <p class="text-xs text-gray-500">{{ $withdrawal->momo_network ?? 'N/A' }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm font-semibold text-gray-900">GHS {{ number_format($withdrawal->amount, 2) }}</td>
                                <td class="px-6 py-4 text-sm">
                                    @if ($withdrawal->status === \App\Models\VendorWithdrawal::STATUS_APPROVED)
                                        <x-badge variant="completed">Approved</x-badge>
                                    @elseif ($withdrawal->status === \App\Models\VendorWithdrawal::STATUS_PROCESSING)
                                        <x-badge variant="processing">Processing</x-badge>
                                    @elseif ($withdrawal->status === \App\Models\VendorWithdrawal::STATUS_REJECTED)
                                        <x-badge variant="warning">Rejected</x-badge>
                                    @else
                                        <x-badge variant="pending">Pending</x-badge>
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
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const momoNumberInput = document.getElementById('momo_number');
    const networkRadios = document.querySelectorAll('input[name="momo_network"]');
    const accountNameContainer = document.getElementById('account-name-container');
    const accountNameDisplay = document.getElementById('account-name-display');
    const loadingContainer = document.getElementById('account-lookup-loading');
    const errorContainer = document.getElementById('account-lookup-error');
    const errorMessage = document.getElementById('account-error-message');
    
    let lookupTimeout = null;
    let lastLookupKey = '';
    
    function getSelectedNetwork() {
        const selected = document.querySelector('input[name="momo_network"]:checked');
        return selected ? selected.value : null;
    }
    
    function hideAllFeedback() {
        accountNameContainer.classList.add('hidden');
        loadingContainer.classList.add('hidden');
        errorContainer.classList.add('hidden');
    }
    
    function showLoading() {
        hideAllFeedback();
        loadingContainer.classList.remove('hidden');
    }
    
    function showAccountName(name) {
        hideAllFeedback();
        accountNameDisplay.textContent = name;
        accountNameContainer.classList.remove('hidden');
    }
    
    function showError(message) {
        hideAllFeedback();
        errorMessage.textContent = message;
        errorContainer.classList.remove('hidden');
    }
    
    function isValidPhoneNumber(phone) {
        return /^0[235][0-9]{8}$/.test(phone);
    }
    
    function lookupAccount() {
        const phone = momoNumberInput.value.trim();
        const network = getSelectedNetwork();
        
        // Create a unique key to avoid duplicate lookups
        const lookupKey = phone + '-' + network;
        
        if (!phone || !network || !isValidPhoneNumber(phone)) {
            hideAllFeedback();
            return;
        }
        
        // Don't lookup again if same details
        if (lookupKey === lastLookupKey) {
            return;
        }
        
        lastLookupKey = lookupKey;
        showLoading();
        
        fetch('{{ route("vendor.withdrawals.lookup") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify({
                momo_number: phone,
                momo_network: network
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.account_name) {
                showAccountName(data.account_name);
            } else {
                showError(data.message || 'Could not verify account. Please check the number and network.');
            }
        })
        .catch(error => {
            console.error('Lookup error:', error);
            showError('Network error. Please try again.');
        });
    }
    
    function debouncedLookup() {
        clearTimeout(lookupTimeout);
        lookupTimeout = setTimeout(lookupAccount, 800);
    }
    
    // Listen for changes on phone number input
    momoNumberInput.addEventListener('input', function() {
        lastLookupKey = ''; // Reset so lookup triggers
        debouncedLookup();
    });
    
    // Listen for changes on network selection
    networkRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            lastLookupKey = ''; // Reset so lookup triggers
            debouncedLookup();
        });
    });
});
</script>
@endpush