@extends('layouts.app')

@section('title', 'AFA Registration - ' . $vendor->name)
@section('description', 'Register for AFA services with ' . $vendor->name)

@section('content')
<script>
    window.afaRegisterData = {
        requiresInlineMomo: {{ ($requiresInlineMomo ?? false) ? 'true' : 'false' }},
        verifyRoute: '{{ route('afa.verify') }}',
    };
</script>
<div class="min-h-screen bg-gradient-to-br from-blue-50 via-white to-green-50 py-8 sm:py-12">
    <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="text-center mb-8">
            <a href="{{ route('storefront.vendor', $vendor->vendor_code) }}" class="inline-flex items-center text-gray-500 hover:text-gray-700 mb-4">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Back to {{ $vendor->name }}
            </a>
            <div class="inline-flex items-center justify-center w-16 h-16 bg-gradient-to-r from-green-500 to-green-600 rounded-full mb-4">
                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
            </div>
            <h1 class="text-3xl sm:text-4xl font-bold text-gray-900">AFA Registration</h1>
            <p class="mt-2 text-gray-600">Complete your registration details below</p>
            <div class="mt-4 inline-flex items-center px-4 py-2 bg-green-100 rounded-full">
                <span class="text-sm font-medium text-green-700">Registration Fee: GH₵ {{ number_format($price, 2) }}</span>
            </div>
        </div>

        <!-- Error Messages -->
        @if(session('error'))
            <div class="mb-6 bg-red-50 border border-red-200 rounded-xl p-4">
                <div class="flex items-center">
                    <svg class="h-5 w-5 text-red-500 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <p class="text-red-700">{{ session('error') }}</p>
                </div>
            </div>
        @endif

        <!-- Registration Form -->
        <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
            <div class="p-6 sm:p-8">
                <form id="afa-register-form" action="{{ route('afa.store', $vendor->vendor_code) }}" method="POST" class="space-y-6">
                    @csrf

                    <!-- Full Name -->
                    <div>
                        <label for="full_name" class="block text-sm font-medium text-gray-700 mb-2">
                            Full Name on Ghana Card <span class="text-red-500">*</span>
                        </label>
                        <input 
                            type="text" 
                            name="full_name" 
                            id="full_name"
                            value="{{ old('full_name') }}"
                            placeholder="John Doe"
                            class="block w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 @error('full_name') border-red-500 @enderror"
                            required
                        >
                        @error('full_name')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- ID Type and Number -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="id_type" class="block text-sm font-medium text-gray-700 mb-2">
                                ID Type <span class="text-red-500">*</span>
                            </label>
                            <select 
                                name="id_type" 
                                id="id_type"
                                class="block w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 @error('id_type') border-red-500 @enderror"
                                required
                            >
                                @foreach($idTypes as $value => $label)
                                    <option value="{{ $value }}" {{ old('id_type') === $value ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('id_type')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="id_number" class="block text-sm font-medium text-gray-700 mb-2">
                                ID Number <span class="text-red-500">*</span>
                            </label>
                            <input 
                                type="text" 
                                name="id_number" 
                                id="id_number"
                                value="{{ old('id_number') }}"
                                placeholder="GHA-XXXXXXXXX-X"
                                class="block w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 uppercase @error('id_number') border-red-500 @enderror"
                                required
                            >
                            <p id="id_hint" class="mt-1 text-xs text-gray-500">Format: GHA-123456789-0</p>
                            <p id="id_error" class="mt-1 text-sm text-red-600 hidden"></p>
                            @error('id_number')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <!-- Date of Birth -->
                    <div>
                        <label for="date_of_birth" class="block text-sm font-medium text-gray-700 mb-2">
                            Date of Birth <span class="text-red-500">*</span>
                        </label>
                        <input 
                            type="date" 
                            name="date_of_birth" 
                            id="date_of_birth"
                            value="{{ old('date_of_birth') }}"
                            max="{{ date('Y-m-d') }}"
                            class="block w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 @error('date_of_birth') border-red-500 @enderror"
                            required
                        >
                        @error('date_of_birth')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Phone Number -->
                    <div>
                        <label for="phone_number" class="block text-sm font-medium text-gray-700 mb-2">
                            Phone Number <span class="text-red-500">*</span>
                        </label>
                        <input 
                            type="tel" 
                            name="phone_number" 
                            id="phone_number"
                            value="{{ old('phone_number') }}"
                            placeholder="0544797799"
                            inputmode="tel"
                            autocomplete="tel"
                            class="block w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 @error('phone_number') border-red-500 @enderror"
                            required
                        >
                        @error('phone_number')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Location -->
                    <div>
                        <label for="location" class="block text-sm font-medium text-gray-700 mb-2">
                            Location (Town/City) <span class="text-red-500">*</span>
                        </label>
                        <input 
                            type="text" 
                            name="location" 
                            id="location"
                            value="{{ old('location') }}"
                            placeholder="Kumasi"
                            class="block w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 @error('location') border-red-500 @enderror"
                            required
                        >
                        @error('location')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Region -->
                    <div>
                        <label for="region" class="block text-sm font-medium text-gray-700 mb-2">
                            Region <span class="text-red-500">*</span>
                        </label>
                        <select 
                            name="region" 
                            id="region"
                            class="block w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 @error('region') border-red-500 @enderror"
                            required
                        >
                            <option value="">Select Region</option>
                            @foreach($regions as $region)
                                <option value="{{ $region }}" {{ old('region') === $region ? 'selected' : '' }}>{{ $region }}</option>
                            @endforeach
                        </select>
                        @error('region')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Occupation -->
                    <div>
                        <label for="occupation" class="block text-sm font-medium text-gray-700 mb-2">
                            Occupation
                        </label>
                        <input 
                            type="text" 
                            name="occupation" 
                            id="occupation"
                            value="{{ old('occupation') }}"
                            placeholder="Farmer, Teacher, etc."
                            class="block w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 @error('occupation') border-red-500 @enderror"
                        >
                        @error('occupation')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <hr class="border-gray-200">

                    <!-- Summary -->
                    <div class="bg-green-50 rounded-xl p-4">
                        <div class="flex items-center justify-between">
                            <span class="text-gray-700">Registration Fee</span>
                            <span class="text-xl font-bold text-green-700">GH₵ {{ number_format($price, 2) }}</span>
                        </div>
                        <p class="mt-2 text-sm text-gray-500">
                            By submitting, you agree to pay the registration fee via Mobile Money.
                        </p>
                    </div>

                    <!-- Submit Button -->
                    <button 
                        type="submit"
                        id="afa-proceed-btn"
                        class="w-full flex items-center justify-center px-6 py-4 bg-gradient-to-r from-green-500 to-green-600 text-white font-semibold text-lg rounded-xl hover:from-green-600 hover:to-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-all"
                    >
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Proceed to Payment
                    </button>

                    <div id="afa-order-message" class="mt-4 text-sm text-gray-600 hidden"></div>
                </form>
            </div>
        </div>

        <!-- Vendor Info -->
        <div class="mt-6 text-center text-sm text-gray-500">
            <p>Processing by: <span class="font-medium text-gray-700">{{ $vendor->name }}</span></p>
            @if($vendor->phone_number)
                <p class="mt-1">
                    Need help? 
                    <a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $vendor->phone_number) }}" target="_blank" class="text-green-600 hover:text-green-700">
                        Contact vendor on WhatsApp
                    </a>
                </p>
            @endif
        </div>
    </div>
</div>

{{-- Inline MoMo Modal (for inline gateways like BulkClix) --}}
<div id="afa-momo-modal" class="fixed inset-0 z-50 hidden items-center justify-center">
    <div class="absolute inset-0 bg-black/50"></div>
    <div class="relative bg-white rounded-xl shadow-md w-full max-w-md p-6 mx-4">
        <h4 class="text-lg font-semibold mb-4">Mobile Money Payment</h4>

        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4">
            <div class="flex items-start gap-3">
                <svg class="w-5 h-5 text-amber-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M12 2a10 10 0 100 20 10 10 0 000-20z"/>
                </svg>
                <div class="text-sm text-amber-900">
                    <p class="font-semibold">Didn’t receive the MoMo prompt?</p>
                    <p class="mt-1 text-amber-800">
                        You can approve the payment manually from your MoMo menu:
                    </p>
                    <ol class="mt-2 list-decimal pl-5 space-y-1 text-amber-800">
                        <li>Dial <span class="font-semibold">*170#</span></li>
                        <li>Select <span class="font-semibold">6</span> (My Wallet)</li>
                        <li>Select <span class="font-semibold">3</span> (My Approvals)</li>
                        <li>Approve the pending transaction</li>
                    </ol>
                    <p class="mt-2 text-xs text-amber-700">
                        Tip: Make sure your phone has network signal and you have enough balance to cover any MoMo charges.
                    </p>
                </div>
            </div>
        </div>

        <div class="mb-3">
            <label for="afa_payer_phone" class="block text-sm text-gray-600 mb-1">MoMo number</label>
            <input type="tel"
                   id="afa_payer_phone"
                     name="payer_phone"
                   inputmode="tel"
                   autocomplete="tel"
                   class="w-full border border-gray-200 rounded-lg p-3 focus:ring-2 focus:ring-green-300"
                   placeholder="e.g. 0551234567">
        </div>

        <div class="mb-4">
            <label for="afa_payer_network" class="block text-sm text-gray-600 mb-1">Network</label>
            <select id="afa_payer_network"
                    name="payer_network"
                    class="w-full border border-gray-200 rounded-lg p-3 focus:ring-2 focus:ring-green-300">
                <option value="">Select network</option>
                <option value="MTN">MTN</option>
                <option value="TELECEL">Telecel</option>
                <option value="AIRTELTIGO">AirtelTigo</option>
            </select>
        </div>

        <div class="flex gap-3">
            <button type="button"
                    id="afa-momo-cancel"
                    class="flex-1 border border-gray-200 rounded-xl py-3 font-semibold text-gray-700 hover:bg-gray-50">
                Cancel
            </button>
            <button type="button"
                    id="afa-momo-confirm"
                    class="flex-1 bg-green-600 hover:bg-green-700 text-white rounded-xl py-3 font-semibold">
                Send Prompt
            </button>
        </div>

        <p class="text-xs text-gray-500 mt-3">Enter the number that should receive the payment prompt.</p>
    </div>
</div>

{{-- Full-screen payment confirmation overlay (inline gateways) --}}
<div id="afa-verification-overlay" class="fixed inset-0 z-50 hidden items-center justify-center">
    <div class="absolute inset-0 bg-black/60 backdrop-blur-sm"></div>
    <div class="relative w-full max-w-lg mx-4 rounded-2xl bg-white shadow-2xl overflow-hidden">
        <div class="p-6 sm:p-8">
            <div class="flex items-center gap-4">
                <div class="relative flex h-14 w-14 items-center justify-center rounded-2xl bg-green-50">
                    <svg class="h-8 w-8 text-green-600" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M12 2a10 10 0 100 20 10 10 0 000-20z" stroke="currentColor" stroke-width="2" opacity="0.2" />
                        <path d="M12 6v6l4 2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <svg class="absolute -inset-1 h-16 w-16 animate-spin text-green-600" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v3a5 5 0 00-5 5H4z"></path>
                    </svg>
                </div>

                <div class="flex-1">
                    <h3 class="text-xl font-semibold text-gray-900">Confirming payment status</h3>
                    <p id="afa-overlay-text" class="mt-1 text-sm text-gray-600">We sent the MoMo prompt and are checking your payment status. This usually takes a moment.</p>
                </div>
            </div>

            <div class="mt-6 rounded-xl border border-gray-100 bg-gray-50 p-4">
                <div class="flex items-start gap-3">
                    <div class="mt-0.5 h-7 w-7 rounded-full bg-green-100 flex items-center justify-center">
                        <svg class="h-4 w-4 text-green-700" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8.75 3.5a.75.75 0 001.5 0V9a.75.75 0 00-1.5 0v4.5zm.75-7.25a1 1 0 100 2 1 1 0 000-2z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div class="text-sm text-gray-700">
                        <div class="font-medium">Don’t close or refresh this page</div>
                        <div class="mt-0.5 text-gray-600">Approve the MoMo prompt on your phone to complete payment. No extra code entry is required here.</div>
                    </div>
                </div>
            </div>

            <div class="mt-6">
                <div class="h-2 w-full rounded-full bg-gray-100 overflow-hidden">
                    <div class="h-2 w-1/3 rounded-full bg-green-600 animate-pulse"></div>
                </div>
                <p class="mt-3 text-xs text-gray-500">You’ll be redirected automatically once payment is confirmed.</p>
            </div>
        </div>
    </div>
</div>

{{-- Full-screen failure overlay --}}
<div id="afa-failure-overlay" class="fixed inset-0 z-50 hidden items-center justify-center">
    <div class="absolute inset-0 bg-black/60 backdrop-blur-sm"></div>
    <div class="relative w-full max-w-lg mx-4 rounded-2xl bg-white shadow-2xl overflow-hidden">
        <div class="p-6 sm:p-8">
            <div class="flex items-center gap-4">
                <div class="relative flex h-14 w-14 items-center justify-center rounded-2xl bg-red-50">
                    <svg class="h-8 w-8 text-red-600" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M12 2a10 10 0 100 20 10 10 0 000-20z" stroke="currentColor" stroke-width="2" opacity="0.2" />
                        <path d="M15 9l-6 6M9 9l6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </div>

                <div class="flex-1">
                    <h3 class="text-xl font-semibold text-gray-900">Payment failed</h3>
                    <p id="afa-failure-text" class="mt-1 text-sm text-gray-600">Your payment was not completed. Please try again.</p>
                </div>
            </div>

            <div class="mt-6 rounded-xl border border-red-100 bg-red-50 p-4">
                <div class="text-sm text-red-800">You will be returned to the form so you can try again.</div>
            </div>

            <div class="mt-6 flex gap-3">
                <button type="button"
                        id="afa-failure-back"
                        class="flex-1 bg-green-600 hover:bg-green-700 text-white rounded-xl py-3 font-semibold">
                    Back to Form
                </button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const cfg = window.afaRegisterData || {};
    const requiresInlineMomo = !!cfg.requiresInlineMomo;
    const verifyRoute = cfg.verifyRoute || '';

    const form = document.getElementById('afa-register-form');
    const proceedBtn = document.getElementById('afa-proceed-btn');
    const messageEl = document.getElementById('afa-order-message');
    const overlay = document.getElementById('afa-verification-overlay');
    const overlayText = document.getElementById('afa-overlay-text');
    const failureOverlay = document.getElementById('afa-failure-overlay');
    const failureText = document.getElementById('afa-failure-text');
    const failureBack = document.getElementById('afa-failure-back');
    const momoModal = document.getElementById('afa-momo-modal');
    const momoPhone = document.getElementById('afa_payer_phone');
    const momoNetwork = document.getElementById('afa_payer_network');
    const momoCancel = document.getElementById('afa-momo-cancel');
    const momoConfirm = document.getElementById('afa-momo-confirm');

    let pollTimer = null;
    let verifyInFlight = false;
    let activeReference = null;

    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    function setMessage(text, type = 'info') {
        if (!messageEl) return;
        if (!text) {
            messageEl.classList.add('hidden');
            messageEl.textContent = '';
            return;
        }
        messageEl.classList.remove('hidden');
        messageEl.textContent = text;
        messageEl.classList.remove('text-red-600', 'text-green-700', 'text-gray-600');
        messageEl.classList.add(type === 'error' ? 'text-red-600' : (type === 'success' ? 'text-green-700' : 'text-gray-600'));
    }

    function showOverlay(text) {
        if (overlayText) overlayText.textContent = text || 'We sent the MoMo prompt and are checking your payment status. This usually takes a moment.';
        if (overlay) {
            overlay.classList.remove('hidden');
            overlay.classList.add('flex');
        }
    }

    function hideOverlay() {
        if (overlay) {
            overlay.classList.add('hidden');
            overlay.classList.remove('flex');
        }
    }

    function showFailure(message) {
        hideOverlay();
        setMessage('', 'info');

        if (failureText) {
            failureText.textContent = message || 'Your payment was not completed. Please try again.';
        }
        if (failureOverlay) {
            failureOverlay.classList.remove('hidden');
            failureOverlay.classList.add('flex');
        }

        setTimeout(() => {
            hideFailure();
        }, 2500);
    }

    function hideFailure() {
        if (failureOverlay) {
            failureOverlay.classList.add('hidden');
            failureOverlay.classList.remove('flex');
        }
        if (proceedBtn) proceedBtn.disabled = false;
        // Return focus to submit area.
        if (proceedBtn && typeof proceedBtn.scrollIntoView === 'function') {
            proceedBtn.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    function showMomoModal() {
        if (momoPhone) momoPhone.value = '';
        if (momoNetwork) momoNetwork.value = '';
        if (momoModal) {
            momoModal.classList.remove('hidden');
            momoModal.classList.add('flex');
        }

        // Focus for quicker entry.
        if (momoPhone && typeof momoPhone.focus === 'function') {
            setTimeout(() => momoPhone.focus(), 0);
        }
    }

    function hideMomoModal() {
        if (momoModal) {
            momoModal.classList.add('hidden');
            momoModal.classList.remove('flex');
        }
    }

    function stopPolling() {
        if (pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }
        verifyInFlight = false;
        activeReference = null;
    }

    function nextIntervalMs(attempt) {
        if (attempt < 6) return 1500;
        if (attempt < 14) return 2000;
        return 3000;
    }

    async function poll(reference, attempt = 0) {
        if (!verifyRoute) return;
        if (activeReference !== reference) return;

        if (verifyInFlight) {
            pollTimer = setTimeout(() => poll(reference, attempt + 1), nextIntervalMs(attempt));
            return;
        }

        verifyInFlight = true;
        try {
            const res = await fetch(verifyRoute, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ reference })
            });

            if (res.ok) {
                const data = await res.json();
                if (data?.status === 'success' && data?.redirect) {
                    setMessage(data.message || 'Payment confirmed. Redirecting…', 'success');
                    showOverlay(data.message || 'Payment confirmed. Redirecting…');
                    stopPolling();
                    window.location.href = data.redirect;
                    return;
                }

                if (data?.status === 'failed') {
                    stopPolling();
                    showFailure(data.message || 'Payment failed. Please try again.');
                    return;
                }

                if (data?.message) {
                    setMessage(data.message, 'info');
                }
            }
        } catch (e) {
            // ignore transient
        } finally {
            verifyInFlight = false;
        }

        pollTimer = setTimeout(() => poll(reference, attempt + 1), nextIntervalMs(attempt));
    }

    async function submitAjax(payerPhoneValue = null, payerNetworkValue = null) {
        if (!form) return;
        if (proceedBtn) proceedBtn.disabled = true;

        if (requiresInlineMomo) {
            showOverlay('Initiating payment… Please wait for the MoMo prompt.');
        }

        setMessage('', 'info');

        const fd = new FormData(form);
        if (requiresInlineMomo) {
            if (payerPhoneValue) fd.set('payer_phone', payerPhoneValue);
            if (payerNetworkValue) fd.set('payer_network', payerNetworkValue);
        }

        try {
            const res = await fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-TOKEN': csrf(),
                    'Accept': 'application/json'
                },
                body: fd,
            });

            if (!res.ok) {
                let msg = 'Submission failed.';
                try {
                    const data = await res.json();
                    if (data?.message) msg = data.message;
                    if (data?.errors && typeof data.errors === 'object') {
                        const firstField = Object.keys(data.errors)[0];
                        const firstMsg = Array.isArray(data.errors[firstField]) ? data.errors[firstField][0] : String(data.errors[firstField]);
                        msg = firstMsg || msg;
                    }
                } catch (e) {
                    // ignore
                }

                hideOverlay();
                setMessage(msg, 'error');
                if (proceedBtn) proceedBtn.disabled = false;
                return;
            }

            const data = await res.json();
            if (data?.success) {
                if (data?.redirect) {
                    window.location.href = data.redirect;
                    return;
                }

                if (requiresInlineMomo && data?.reference && verifyRoute) {
                    setMessage(data.message || 'Payment initiated. Check your phone and approve the MoMo prompt.', 'info');
                    showOverlay('Waiting for payment confirmation…');
                    stopPolling();
                    activeReference = data.reference;
                    poll(data.reference, 0);
                    return;
                }

                hideOverlay();
                setMessage(data.message || 'Submitted.', 'success');
            } else {
                hideOverlay();
                setMessage(data?.message || 'Submission failed.', 'error');
                if (proceedBtn) proceedBtn.disabled = false;
            }
        } catch (e) {
            hideOverlay();
            setMessage('Network error. Please try again.', 'error');
            if (proceedBtn) proceedBtn.disabled = false;
        }
    }

    const idTypeSelect = document.getElementById('id_type');
    const idNumberInput = document.getElementById('id_number');
    const idHint = document.getElementById('id_hint');
    const idError = document.getElementById('id_error');
    
    const validationRules = {
        ghana_card: {
            pattern: /^GHA-[0-9]{9}-[0-9]$/i,
            placeholder: 'GHA-XXXXXXXXX-X',
            hint: 'Format: GHA-123456789-0',
            error: 'Ghana Card must be in format: GHA-123456789-0'
        },
        drivers_license: {
            pattern: /^[A-Z0-9]{9,12}$/i,
            placeholder: 'XXXXXXXXXXXX',
            hint: 'Enter 9-12 alphanumeric characters',
            error: 'Driver\'s License must be 9-12 alphanumeric characters'
        },
        voters_id: {
            pattern: /^[0-9]{10}$/,
            placeholder: 'XXXXXXXXXX',
            hint: 'Enter 10 digit Voter\'s ID number',
            error: 'Voter\'s ID must be exactly 10 digits'
        }
    };
    
    function updateIdField() {
        const selectedType = idTypeSelect.value;
        const rules = validationRules[selectedType];
        
        if (rules) {
            idNumberInput.placeholder = rules.placeholder;
            idHint.textContent = rules.hint;
            idHint.classList.remove('hidden');
            validateIdNumber();
        }
    }
    
    function validateIdNumber() {
        const selectedType = idTypeSelect.value;
        const rules = validationRules[selectedType];
        const value = idNumberInput.value.trim().toUpperCase();
        
        idError.classList.add('hidden');
        idNumberInput.classList.remove('border-red-500', 'border-green-500');
        
        if (!value) return true;
        
        if (rules && !rules.pattern.test(value)) {
            idError.textContent = rules.error;
            idError.classList.remove('hidden');
            idNumberInput.classList.add('border-red-500');
            return false;
        } else if (rules) {
            idNumberInput.classList.add('border-green-500');
        }
        
        return true;
    }
    
    // Auto-format Ghana Card number
    idNumberInput.addEventListener('input', function(e) {
        let value = e.target.value.toUpperCase();
        
        if (idTypeSelect.value === 'ghana_card') {
            // Remove all non-alphanumeric characters except hyphens
            value = value.replace(/[^A-Z0-9-]/g, '');
            
            // Auto-add hyphens for Ghana Card format
            if (value.length === 3 && !value.includes('-')) {
                value = value + '-';
            } else if (value.length === 13 && value.charAt(12) !== '-') {
                value = value.substring(0, 13) + '-' + value.substring(13);
            }
            
            // Limit to 15 characters (GHA-XXXXXXXXX-X)
            if (value.length > 15) {
                value = value.substring(0, 15);
            }
        }
        
        e.target.value = value;
        validateIdNumber();
    });
    
    idTypeSelect.addEventListener('change', function() {
        idNumberInput.value = '';
        updateIdField();
    });
    
    // Form submission validation (+ inline payment interception)
    if (form) {
        form.addEventListener('submit', function(e) {
            if (!validateIdNumber()) {
                e.preventDefault();
                idNumberInput.focus();
                return;
            }

            // For inline gateways (BulkClix), keep the user on-page: show MoMo modal then AJAX submit.
            if (requiresInlineMomo) {
                e.preventDefault();
                showMomoModal();
            }
        });
    }

    if (momoCancel) {
        momoCancel.addEventListener('click', function() {
            hideMomoModal();
            if (proceedBtn) proceedBtn.disabled = false;
        });
    }

    if (momoConfirm) {
        momoConfirm.addEventListener('click', function() {
            const p = momoPhone ? String(momoPhone.value || '').trim() : '';
            const n = momoNetwork ? String(momoNetwork.value || '').trim() : '';
            if (!p || !n) {
                setMessage('Please enter your MoMo number and select network.', 'error');
                return;
            }
            hideMomoModal();
            submitAjax(p, n);
        });
    }

    if (failureBack) {
        failureBack.addEventListener('click', function() {
            hideFailure();
        });
    }
    
    // Initialize
    updateIdField();
});
</script>
@endpush
@endsection
