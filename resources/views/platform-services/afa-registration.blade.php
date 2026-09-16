{{--
    platform-services/afa-registration.blade.php

    Official XTRA4U "AFA Registration" page — the homepage entry point,
    distinct from a vendor's own shared storefront (/store/{vendor}/afa).

    Unlike Data Bundles/ECG/Shop, this page does NOT reuse
    <x-storefront.platform-purchase-panel> (that widget is built for the
    generic Choose Service -> Select Package -> Checkout flow; AFA has its
    own registration form). Instead this view restyles the existing AFA
    registration form (resources/views/afa/register.blade.php) onto the x4
    design system: same field names/ids, same POST target (afa.store), same
    verify/MoMo/payment-polling script copied verbatim — no registration or
    payment logic is duplicated, only the presentation around it. The
    original vendor-scoped page is untouched and keeps its own styling; this
    is a separate, additive view.

    Notably absent from this version: the vendor-branded hero, the "Back to
    {vendor}" link, and the "Processing by: {vendor}" line — a customer
    arriving via the official XTRA4U page should never be routed into that
    vendor's full storefront or see it named, even though the vendor still
    fulfils the registration behind the scenes.
--}}

@extends('layouts.app')

@section('title', 'AFA Registration - XTRA4U')
@section('description', 'Register for AFA (Agricultural Finance Authority) services instantly on XTRA4U. Secure Mobile Money payments.')

{{-- Scope the storefront design system to this page only. --}}
@section('body-class', 'x4')

@php
    $shopUrl = route('services.afa-registration');
@endphp

@section('site-header')
    <x-storefront.header :shop-url="$shopUrl" />
@endsection

@section('site-footer')
    <x-storefront.footer :shop-url="$shopUrl" />
@endsection

@section('content')
<script>
    window.afaRegisterData = {
        requiresInlineMomo: {{ ($requiresInlineMomo ?? false) ? 'true' : 'false' }},
        verifyRoute: '{{ route('afa.verify') }}',
    };
</script>

<div class="x4-page" style="padding-top: 64px;">
    {{-- ============================================================
         Hero
         ============================================================ --}}
    <section class="relative overflow-hidden" style="background: #fff;">
        <div class="x4-hero-wash absolute inset-0" aria-hidden="true" style="pointer-events: none;"></div>

        <div class="relative max-w-6xl mx-auto px-5 py-10 sm:py-14 text-center">
            <x-storefront.reveal from="up" class="max-w-2xl mx-auto">
                <x-storefront.eyebrow>AFA Registration</x-storefront.eyebrow>

                <h1 class="x4-display-xl mt-4 mb-3" style="color: var(--x4-ink-strong);">
                    Register for <span style="color: var(--x4-violet);">AFA</span> in minutes
                </h1>

                <p class="x4-body-lg mb-5" style="color: var(--x4-ink-body);">
                    Complete your Agricultural Finance Authority registration securely with Mobile Money.
                </p>

                <div class="flex flex-wrap items-center justify-center gap-2.5">
                    <span
                        class="inline-flex items-center gap-1.5"
                        style="background-color: #dcfce7; color: #166534; border-radius: var(--x4-r-pill); padding: 6px 14px;"
                    >
                        <x-storefront.icon name="shield" class="w-3.5 h-3.5" />
                        <span class="x4-caption" style="font-weight: 500;">Official XTRA4U Service</span>
                    </span>

                    <span
                        class="inline-flex items-center gap-1.5"
                        style="background-color: var(--x4-violet-soft); border-radius: var(--x4-r-pill); padding: 6px 14px;"
                    >
                        <span class="x4-caption" style="color: var(--x4-violet); font-weight: 500;">Registration Fee: GH₵ {{ number_format($price, 2) }}</span>
                    </span>
                </div>
            </x-storefront.reveal>
        </div>
    </section>

    {{-- ============================================================
         Registration form
         ============================================================ --}}
    <div class="max-w-2xl mx-auto px-5" style="padding-top: 32px; padding-bottom: 72px;">
        @if (session('error'))
            <div class="mb-5" style="background-color: #fef2f2; border: 1px solid #fecaca; border-radius: var(--x4-r-md); padding: 14px 16px;">
                <p class="x4-caption" style="color: #991b1b;">{{ session('error') }}</p>
            </div>
        @endif

        <div class="x4-panel" style="padding: 28px;">
            <form id="afa-register-form" action="{{ route('afa.store', $vendor->vendor_code) }}" method="POST">
                @csrf

                <div class="mb-4">
                    <label for="full_name" class="x4-caption block mb-1.5" style="color: var(--x4-ink-mute);">
                        Full Name on Ghana Card <span style="color: #dc2626;">*</span>
                    </label>
                    <input
                        type="text" name="full_name" id="full_name"
                        value="{{ old('full_name') }}"
                        placeholder="John Doe"
                        class="x4-input" required
                    >
                    @error('full_name')
                        <p class="x4-micro-cap mt-1.5" style="color: #dc2626; text-transform: none;">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label for="id_type" class="x4-caption block mb-1.5" style="color: var(--x4-ink-mute);">
                            ID Type <span style="color: #dc2626;">*</span>
                        </label>
                        <select name="id_type" id="id_type" class="x4-input" required>
                            @foreach ($idTypes as $value => $label)
                                <option value="{{ $value }}" {{ old('id_type') === $value ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('id_type')
                            <p class="x4-micro-cap mt-1.5" style="color: #dc2626; text-transform: none;">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="id_number" class="x4-caption block mb-1.5" style="color: var(--x4-ink-mute);">
                            ID Number <span style="color: #dc2626;">*</span>
                        </label>
                        <input
                            type="text" name="id_number" id="id_number"
                            value="{{ old('id_number') }}"
                            placeholder="GHA-XXXXXXXXX-X"
                            class="x4-input" style="text-transform: uppercase;" required
                        >
                        <p id="id_hint" class="x4-micro-cap mt-1.5" style="color: var(--x4-ink-mute); text-transform: none;">Format: GHA-123456789-0</p>
                        <p id="id_error" class="x4-micro-cap mt-1.5 hidden" style="color: #dc2626; text-transform: none;"></p>
                        @error('id_number')
                            <p class="x4-micro-cap mt-1.5" style="color: #dc2626; text-transform: none;">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="mb-4">
                    <label for="date_of_birth" class="x4-caption block mb-1.5" style="color: var(--x4-ink-mute);">
                        Date of Birth <span style="color: #dc2626;">*</span>
                    </label>
                    <input
                        type="date" name="date_of_birth" id="date_of_birth"
                        value="{{ old('date_of_birth') }}"
                        max="{{ date('Y-m-d') }}"
                        class="x4-input" required
                    >
                    @error('date_of_birth')
                        <p class="x4-micro-cap mt-1.5" style="color: #dc2626; text-transform: none;">{{ $message }}</p>
                    @enderror
                </div>

                <div class="mb-4">
                    <label for="phone_number" class="x4-caption block mb-1.5" style="color: var(--x4-ink-mute);">
                        Phone Number <span style="color: #dc2626;">*</span>
                    </label>
                    <input
                        type="tel" name="phone_number" id="phone_number"
                        value="{{ old('phone_number') }}"
                        placeholder="0544797799"
                        inputmode="tel" autocomplete="tel"
                        class="x4-input" required
                    >
                    @error('phone_number')
                        <p class="x4-micro-cap mt-1.5" style="color: #dc2626; text-transform: none;">{{ $message }}</p>
                    @enderror
                </div>

                <div class="mb-4">
                    <label for="location" class="x4-caption block mb-1.5" style="color: var(--x4-ink-mute);">
                        Location (Town/City) <span style="color: #dc2626;">*</span>
                    </label>
                    <input
                        type="text" name="location" id="location"
                        value="{{ old('location') }}"
                        placeholder="Kumasi"
                        class="x4-input" required
                    >
                    @error('location')
                        <p class="x4-micro-cap mt-1.5" style="color: #dc2626; text-transform: none;">{{ $message }}</p>
                    @enderror
                </div>

                <div class="mb-4">
                    <label for="region" class="x4-caption block mb-1.5" style="color: var(--x4-ink-mute);">
                        Region <span style="color: #dc2626;">*</span>
                    </label>
                    <select name="region" id="region" class="x4-input" required>
                        <option value="">Select Region</option>
                        @foreach ($regions as $region)
                            <option value="{{ $region }}" {{ old('region') === $region ? 'selected' : '' }}>{{ $region }}</option>
                        @endforeach
                    </select>
                    @error('region')
                        <p class="x4-micro-cap mt-1.5" style="color: #dc2626; text-transform: none;">{{ $message }}</p>
                    @enderror
                </div>

                <div class="mb-5">
                    <label for="occupation" class="x4-caption block mb-1.5" style="color: var(--x4-ink-mute);">Occupation</label>
                    <input
                        type="text" name="occupation" id="occupation"
                        value="{{ old('occupation') }}"
                        placeholder="Farmer, Teacher, etc."
                        class="x4-input"
                    >
                    @error('occupation')
                        <p class="x4-micro-cap mt-1.5" style="color: #dc2626; text-transform: none;">{{ $message }}</p>
                    @enderror
                </div>

                <div style="background-color: var(--x4-canvas-cream); border-radius: var(--x4-r-md); padding: 16px; margin-bottom: 20px;">
                    <div class="flex items-center justify-between">
                        <span class="x4-body-md" style="color: var(--x4-ink);">Registration Fee</span>
                        <span class="x4-tnum" style="font-size: 20px; font-weight: 500; color: var(--x4-violet);">GH₵ {{ number_format($price, 2) }}</span>
                    </div>
                    <p class="x4-micro-cap mt-2" style="color: var(--x4-ink-mute); text-transform: none;">
                        By submitting, you agree to pay the registration fee via Mobile Money.
                    </p>
                </div>

                <button type="submit" id="afa-proceed-btn" class="x4-btn x4-btn-primary w-full" style="padding: 13px 22px;">
                    Proceed to Payment
                </button>

                <p id="afa-order-message" class="x4-caption mt-4 hidden" style="color: var(--x4-ink-mute);"></p>
            </form>
        </div>
    </div>

    {{-- Inline MoMo Modal (for inline gateways like BulkClix) --}}
    <div id="afa-momo-modal" class="fixed inset-0 z-50 hidden items-center justify-center">
        <div class="absolute inset-0" style="background: rgba(13,37,61,0.5);"></div>
        <div class="relative w-full max-w-md mx-4" style="background-color: var(--x4-canvas); border-radius: var(--x4-r-xl); box-shadow: var(--x4-shadow-3); padding: 28px;">
            <h4 class="x4-heading-md mb-4" style="color: var(--x4-ink);">Mobile Money Payment</h4>

            <div style="background-color: #fffbeb; border: 1px solid #fde68a; border-radius: var(--x4-r-md); padding: 14px; margin-bottom: 16px;">
                <div class="flex items-start gap-2.5">
                    <x-storefront.icon name="clock" class="w-4 h-4 flex-shrink-0 mt-0.5" style="color: #b45309;" />
                    <div class="x4-caption" style="color: #78350f;">
                        <p style="font-weight: 500;">Didn't receive the MoMo prompt?</p>
                        <p class="mt-1">You can approve the payment manually from your MoMo menu:</p>
                        <ol class="mt-1.5 space-y-0.5" style="padding-left: 18px; list-style: decimal;">
                            <li>Dial <strong>*170#</strong></li>
                            <li>Select <strong>6</strong> (My Wallet)</li>
                            <li>Select <strong>3</strong> (My Approvals)</li>
                            <li>Approve the pending transaction</li>
                        </ol>
                        <p class="mt-1.5 x4-micro-cap" style="text-transform: none; color: #92400e;">
                            Tip: make sure your phone has signal and enough balance for any MoMo charges.
                        </p>
                    </div>
                </div>
            </div>

            <div class="mb-3">
                <label for="afa_payer_phone" class="x4-caption block mb-1.5" style="color: var(--x4-ink-mute);">MoMo number</label>
                <input type="tel" id="afa_payer_phone" name="payer_phone" inputmode="tel" autocomplete="tel" placeholder="e.g. 0551234567" class="x4-input">
            </div>

            <div class="mb-5">
                <label for="afa_payer_network" class="x4-caption block mb-1.5" style="color: var(--x4-ink-mute);">Network</label>
                <select id="afa_payer_network" name="payer_network" class="x4-input">
                    <option value="">Select network</option>
                    <option value="MTN">MTN</option>
                    <option value="TELECEL">Telecel</option>
                    <option value="AIRTELTIGO">AirtelTigo</option>
                </select>
            </div>

            <div class="flex gap-3">
                <button type="button" id="afa-momo-cancel" class="x4-btn x4-btn-outline flex-1">Cancel</button>
                <button type="button" id="afa-momo-confirm" class="x4-btn x4-btn-primary flex-1">Send Prompt</button>
            </div>

            <p class="x4-micro-cap mt-3" style="text-transform: none; color: var(--x4-ink-mute);">
                Enter the number that should receive the payment prompt.
            </p>
        </div>
    </div>

    {{-- Full-screen payment confirmation overlay (inline gateways) --}}
    <div id="afa-verification-overlay" class="fixed inset-0 z-50 hidden items-center justify-center">
        <div class="absolute inset-0" style="background: rgba(13,37,61,0.6); backdrop-filter: blur(4px);"></div>
        <div class="relative w-full max-w-lg mx-4" style="background-color: var(--x4-canvas); border-radius: var(--x4-r-xl); box-shadow: var(--x4-shadow-3); overflow: hidden;">
            <div style="padding: 32px;">
                <div class="flex items-center gap-4">
                    <div class="relative flex-shrink-0 flex items-center justify-center" style="width: 56px; height: 56px; border-radius: var(--x4-r-lg); background-color: var(--x4-violet-soft);">
                        <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" style="color: var(--x4-violet); opacity: 0.35;" aria-hidden="true">
                            <path d="M12 2a10 10 0 100 20 10 10 0 000-20z" stroke="currentColor" stroke-width="2" />
                            <path d="M12 6v6l4 2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <svg class="absolute animate-spin" style="top: -4px; left: -4px; width: 64px; height: 64px; color: var(--x4-violet);" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v3a5 5 0 00-5 5H4z"></path>
                        </svg>
                    </div>

                    <div class="flex-1">
                        <h3 class="x4-heading-md" style="color: var(--x4-ink);">Confirming payment status</h3>
                        <p id="afa-overlay-text" class="x4-body-md mt-1" style="color: var(--x4-ink-mute);">We sent the MoMo prompt and are checking your payment status. This usually takes a moment.</p>
                    </div>
                </div>

                <div class="mt-6" style="background-color: var(--x4-canvas-soft); border: 1px solid var(--x4-hairline); border-radius: var(--x4-r-md); padding: 16px;">
                    <div class="flex items-start gap-3">
                        <span class="flex-shrink-0 flex items-center justify-center" style="width: 28px; height: 28px; border-radius: 9999px; background-color: var(--x4-violet-soft);">
                            <x-storefront.icon name="clock" class="w-3.5 h-3.5" style="color: var(--x4-violet);" />
                        </span>
                        <div class="x4-caption" style="color: var(--x4-ink-sec);">
                            <p style="font-weight: 500; color: var(--x4-ink);">Don't close or refresh this page</p>
                            <p class="mt-0.5">Approve the MoMo prompt on your phone to complete payment. No extra code entry is required here.</p>
                        </div>
                    </div>
                </div>

                <div class="mt-6">
                    <div style="height: 6px; width: 100%; border-radius: 9999px; background-color: var(--x4-canvas-soft); overflow: hidden;">
                        <div class="animate-pulse" style="height: 100%; width: 33%; border-radius: 9999px; background-color: var(--x4-violet);"></div>
                    </div>
                    <p class="x4-micro-cap mt-2.5" style="text-transform: none; color: var(--x4-ink-mute);">You will be redirected automatically once payment is confirmed.</p>
                </div>
            </div>
        </div>
    </div>

    {{-- Full-screen failure overlay --}}
    <div id="afa-failure-overlay" class="fixed inset-0 z-50 hidden items-center justify-center">
        <div class="absolute inset-0" style="background: rgba(13,37,61,0.6); backdrop-filter: blur(4px);"></div>
        <div class="relative w-full max-w-lg mx-4" style="background-color: var(--x4-canvas); border-radius: var(--x4-r-xl); box-shadow: var(--x4-shadow-3); overflow: hidden;">
            <div style="padding: 32px;">
                <div class="flex items-center gap-4">
                    <div class="flex-shrink-0 flex items-center justify-center" style="width: 56px; height: 56px; border-radius: var(--x4-r-lg); background-color: #fee2e2;">
                        <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" style="color: #dc2626;" aria-hidden="true">
                            <path d="M12 2a10 10 0 100 20 10 10 0 000-20z" stroke="currentColor" stroke-width="2" opacity="0.25" />
                            <path d="M15 9l-6 6M9 9l6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </div>

                    <div class="flex-1">
                        <h3 class="x4-heading-md" style="color: var(--x4-ink);">Payment failed</h3>
                        <p id="afa-failure-text" class="x4-body-md mt-1" style="color: var(--x4-ink-mute);">Your payment was not completed. Please try again.</p>
                    </div>
                </div>

                <div class="mt-6" style="background-color: #fef2f2; border: 1px solid #fecaca; border-radius: var(--x4-r-md); padding: 14px;">
                    <p class="x4-caption" style="color: #991b1b;">You will be returned to the form so you can try again.</p>
                </div>

                <div class="mt-6">
                    <button type="button" id="afa-failure-back" class="x4-btn x4-btn-primary w-full">Back to Form</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ============================================================
         Trust / Support
         ============================================================ --}}
    <section style="background-color: var(--x4-canvas-soft); border-top: 1px solid var(--x4-hairline); padding: 56px 0;">
        <x-storefront.reveal class="max-w-6xl mx-auto px-5">
            <div class="text-center" style="background-color: var(--x4-canvas); border: 1px solid var(--x4-hairline); border-radius: var(--x4-r-xl); box-shadow: var(--x4-shadow-1); padding: 40px 24px;">
                <h2 class="x4-display-md mb-3" style="color: var(--x4-ink);">Need Help?</h2>
                <p class="x4-body-lg mb-6" style="color: var(--x4-ink-sec);">
                    Contact support if you have questions about your registration.
                </p>
                @if ($vendor->phone_number)
                    <a
                        href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $vendor->phone_number) }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="x4-btn"
                        style="background-color: #22c55e; color: #fff; border: 1px solid #22c55e; padding: 13px 26px;"
                    >
                        <x-storefront.icon name="whatsapp" class="w-4 h-4" />
                        Contact support: {{ $vendor->phone_number }}
                    </a>
                @endif
            </div>
        </x-storefront.reveal>
    </section>
</div>
@endsection

{{--
    Script below is copied verbatim from resources/views/afa/register.blade.php
    (same element ids/names, same fetch targets) — no registration or payment
    logic is duplicated in spirit, only reused as-is so this page behaves
    identically to the existing vendor-scoped AFA form.
--}}
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
