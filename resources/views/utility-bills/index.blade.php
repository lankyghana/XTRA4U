{{--
    utility-bills/index.blade.php

    Global Utility Bills page (KiNG FLEXY). Served both at /services/utility-bills
    (direct XTRA4U sale) and /store/{vendor_code}/utility-bills (storefront sale).

    Flow: choose biller -> enter details -> server-side account verification ->
    customer explicitly confirms (and, for ECG-style billers, picks a meter) ->
    amount -> pay. The browser only ever holds an opaque lookup token and a choice;
    the account, verified name, vendor attribution and limits are server-side.
--}}
@extends('layouts.app')

@section('title', 'Utility Bills - Electricity, Water & TV - XTRA4U')
@section('description', 'Pay electricity, water and TV bills on XTRA4U. Verify the account first, then pay securely with Mobile Money.')

@section('body-class', 'x4')

@section('site-header')
    <x-storefront.header :shop-url="$shopUrl" />
@endsection

@section('site-footer')
    <x-storefront.footer :shop-url="$shopUrl" />
@endsection

@section('content')
<script>
    window.utilityBillsConfig = {!! \Illuminate\Support\Js::from([
        'billers' => $billers,
        'limits' => $limits,
        'routes' => $routes,
        'requiresInlineMomo' => (bool) $requiresInlineMomo,
        'storeKey' => $vendor?->vendor_code ?? 'direct',
    ]) !!};
</script>

<div class="x4-page" style="padding-top: 64px;" x-data="utilityBills(window.utilityBillsConfig)">
    <section class="relative overflow-hidden" style="background: #fff;">
        <div class="x4-hero-wash absolute inset-0" aria-hidden="true" style="pointer-events: none;"></div>
        <div class="relative max-w-3xl mx-auto px-5 py-10 sm:py-12 text-center">
            <x-storefront.eyebrow>Utility Bills</x-storefront.eyebrow>
            <h1 class="x4-display-xl mt-4 mb-3" style="color: var(--x4-ink-strong);">
                Pay your bills, <span style="color: var(--x4-violet);">verified first</span>
            </h1>
            <p class="x4-body-lg" style="color: var(--x4-ink-body);">Pay electricity, water and TV bills. We check the account with the provider before you pay.</p>
        </div>
    </section>

    <div class="max-w-xl mx-auto px-4 sm:px-5" style="padding-top: 8px; padding-bottom: 72px;">
        <div style="background-color: var(--x4-canvas); border: 1px solid var(--x4-hairline); border-radius: var(--x4-r-lg); padding: 20px; box-shadow: var(--x4-shadow-1);">

            {{-- Error banner --}}
            <div x-show="error" x-cloak role="alert" class="mb-4 text-sm" style="background:#fef2f2;color:#991b1b;border:1px solid #fecaca;border-radius:12px;padding:10px 14px;" x-text="error"></div>

            {{-- Step 1: biller --}}
            <div x-show="step === 1">
                <h2 class="x4-heading-md mb-3" style="color: var(--x4-ink);">1. Choose a bill</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <template x-for="b in billers" :key="b.key">
                        <button type="button" @click="chooseBiller(b)"
                                class="text-left"
                                style="border:1px solid var(--x4-hairline);border-radius:12px;padding:14px 16px;background:var(--x4-canvas);min-height:56px;">
                            <span class="block font-semibold" style="color: var(--x4-ink);" x-text="b.label"></span>
                            <span class="block text-xs mt-0.5" style="color: var(--x4-ink-sec);" x-text="b.lookup_by === 'phone' ? 'Find by phone number' : b.account_label"></span>
                        </button>
                    </template>
                </div>
            </div>

            {{-- Step 2: details --}}
            <form x-show="step === 2" x-cloak @submit.prevent="doLookup()">
                <button type="button" class="text-sm mb-3" style="color: var(--x4-violet);" @click="reset()">&larr; Change bill</button>
                <h2 class="x4-heading-md mb-3" style="color: var(--x4-ink);">2. <span x-text="biller?.label"></span> details</h2>

                <template x-if="biller && biller.lookup_by !== 'phone'">
                    <label class="block mb-3">
                        <span class="block text-sm mb-1" style="color: var(--x4-ink-sec);" x-text="biller.account_label"></span>
                        <input type="text" x-model.trim="account" maxlength="30" inputmode="text" autocomplete="off" required
                               class="w-full" style="border:1px solid var(--x4-hairline);border-radius:12px;padding:12px 14px;font-size:16px;">
                        <span class="block text-xs mt-1" style="color:#b91c1c;" x-text="fieldErrors.account"></span>
                    </label>
                </template>

                <template x-if="biller && (biller.requires_phone || biller.lookup_by === 'phone')">
                    <label class="block mb-3">
                        <span class="block text-sm mb-1" style="color: var(--x4-ink-sec);" x-text="biller.lookup_by === 'phone' ? 'Phone number linked to the meter' : 'Phone number'"></span>
                        <input type="tel" x-model.trim="phone" maxlength="20" inputmode="tel" autocomplete="tel" required placeholder="0551234567"
                               class="w-full" style="border:1px solid var(--x4-hairline);border-radius:12px;padding:12px 14px;font-size:16px;">
                        <span class="block text-xs mt-1" style="color:#b91c1c;" x-text="fieldErrors.phone"></span>
                    </label>
                </template>

                <button type="submit" :disabled="busy" class="w-full font-semibold"
                        style="background:var(--x4-violet);color:#fff;border-radius:12px;padding:14px;min-height:48px;" :style="busy ? 'opacity:.6' : ''">
                    <span x-text="busy ? 'Verifying…' : 'Verify account'"></span>
                </button>
            </form>

            {{-- Step 3: confirm (+ meter choice) --}}
            <div x-show="step === 3" x-cloak>
                <button type="button" class="text-sm mb-3" style="color: var(--x4-violet);" @click="step = 2; error = ''">&larr; Edit details</button>
                <h2 class="x4-heading-md mb-3" style="color: var(--x4-ink);">3. Confirm the account</h2>

                <template x-if="result && result.meters.length">
                    <div>
                        <p class="text-sm mb-2" style="color: var(--x4-ink-sec);" x-text="result.meters.length > 1 ? 'We found ' + result.meters.length + ' meters. Select the one you want to pay for.' : 'We found this meter. Select it to continue.'"></p>
                        <div class="space-y-2" role="radiogroup" aria-label="Meters">
                            <template x-for="m in result.meters" :key="m.id">
                                <label class="flex items-start gap-3 cursor-pointer" style="border:1px solid var(--x4-hairline);border-radius:12px;padding:12px 14px;"
                                       :style="meterId === m.id ? 'border-color:var(--x4-violet);background:var(--x4-violet-soft)' : ''">
                                    <input type="radio" name="meter" :value="m.id" x-model="meterId" class="mt-1">
                                    <span class="block">
                                        <span class="block font-semibold" style="color:var(--x4-ink);" x-text="m.name || 'Meter'"></span>
                                        <span class="block text-sm" style="color:var(--x4-ink-sec);">Meter <span x-text="m.meter_masked"></span></span>
                                        <span class="block text-sm" x-show="m.amount_owing" style="color:var(--x4-ink-sec);">Outstanding: GHS <span x-text="m.amount_owing"></span></span>
                                        <span class="block text-sm" x-show="m.account_credit" style="color:#166534;">Account credit: GHS <span x-text="m.account_credit"></span></span>
                                    </span>
                                </label>
                            </template>
                        </div>
                    </div>
                </template>

                <template x-if="result && !result.meters.length">
                    <div style="border:1px solid var(--x4-hairline);border-radius:12px;padding:14px 16px;">
                        <p class="text-xs uppercase tracking-wide" style="color:var(--x4-ink-sec);" x-text="result.biller.label"></p>
                        <p class="font-semibold text-lg" style="color:var(--x4-ink);" x-text="result.account_name || 'Account found'"></p>
                        <p class="text-sm" style="color:var(--x4-ink-sec);"><span x-text="result.biller.account_label"></span>: <span x-text="result.account_masked"></span></p>
                        <p class="text-sm" x-show="result.bouquet" style="color:var(--x4-ink-sec);">Bouquet: <span x-text="result.bouquet"></span></p>
                        <p class="text-sm" x-show="result.amount_owing" style="color:var(--x4-ink-sec);">Amount due: GHS <span x-text="result.amount_owing"></span></p>
                        <p class="text-sm" x-show="result.account_credit" style="color:#166534;">Account credit: GHS <span x-text="result.account_credit"></span></p>
                    </div>
                </template>

                <label class="flex items-start gap-3 mt-4 text-sm" style="color:var(--x4-ink-body);">
                    <input type="checkbox" x-model="confirmed" class="mt-1">
                    <span>I confirm these details are correct.</span>
                </label>

                <button type="button" @click="step = 4" :disabled="!canContinue" class="w-full font-semibold mt-4"
                        style="background:var(--x4-violet);color:#fff;border-radius:12px;padding:14px;min-height:48px;" :style="!canContinue ? 'opacity:.5' : ''">
                    Continue
                </button>
            </div>

            {{-- Step 4: amount + pay --}}
            <form x-show="step === 4" x-cloak @submit.prevent="pay()">
                <button type="button" class="text-sm mb-3" style="color: var(--x4-violet);" @click="step = 3; error = ''">&larr; Back</button>
                <h2 class="x4-heading-md mb-3" style="color: var(--x4-ink);">4. Amount &amp; payment</h2>

                <p class="text-sm mb-2" style="color:var(--x4-ink-sec);">
                    Paying <strong x-text="selectedName"></strong> &middot; <span x-text="selectedMasked"></span>
                </p>

                <label class="block mb-3">
                    <span class="block text-sm mb-1" style="color: var(--x4-ink-sec);">Amount (GHS)</span>
                    <input type="text" x-model.trim="amount" inputmode="decimal" autocomplete="off" required placeholder="0.00"
                           class="w-full" style="border:1px solid var(--x4-hairline);border-radius:12px;padding:12px 14px;font-size:16px;">
                    <span class="block text-xs mt-1" style="color:var(--x4-ink-sec);" x-show="limits.min || limits.max">
                        <span x-show="limits.min">Minimum GHS <span x-text="limits.min"></span></span>
                        <span x-show="limits.min && limits.max"> &middot; </span>
                        <span x-show="limits.max">Maximum GHS <span x-text="limits.max"></span></span>
                    </span>
                    <span class="block text-xs mt-1" style="color:#b91c1c;" x-text="fieldErrors.amount"></span>
                </label>

                <template x-if="requiresInlineMomo">
                    <div>
                        <label class="block mb-3">
                            <span class="block text-sm mb-1" style="color: var(--x4-ink-sec);">Mobile Money number</span>
                            <input type="tel" x-model.trim="payerPhone" inputmode="tel" maxlength="20" required placeholder="0551234567"
                                   class="w-full" style="border:1px solid var(--x4-hairline);border-radius:12px;padding:12px 14px;font-size:16px;">
                            <span class="block text-xs mt-1" style="color:#b91c1c;" x-text="fieldErrors.payer_phone"></span>
                        </label>
                        <label class="block mb-3">
                            <span class="block text-sm mb-1" style="color: var(--x4-ink-sec);">Network</span>
                            <select x-model="payerNetwork" required class="w-full" style="border:1px solid var(--x4-hairline);border-radius:12px;padding:12px 14px;font-size:16px;background:#fff;">
                                <option value="">Select network</option>
                                <option value="MTN">MTN</option>
                                <option value="TELECEL">Telecel</option>
                                <option value="AIRTELTIGO">AirtelTigo</option>
                            </select>
                            <span class="block text-xs mt-1" style="color:#b91c1c;" x-text="fieldErrors.payer_network"></span>
                        </label>
                    </div>
                </template>

                <p class="text-xs mb-3" style="color:var(--x4-ink-sec);">You pay the bill amount. Your gateway may add its own fee at checkout.</p>

                <button type="submit" :disabled="busy" class="w-full font-semibold"
                        style="background:var(--x4-violet);color:#fff;border-radius:12px;padding:14px;min-height:48px;" :style="busy ? 'opacity:.6' : ''">
                    <span x-text="busy ? (waiting ? 'Waiting for payment…' : 'Please wait…') : 'Pay now'"></span>
                </button>
                <p class="text-sm mt-3 text-center" x-show="waiting" style="color:var(--x4-ink-sec);">Approve the Mobile Money prompt on your phone. Please don't close this page.</p>
            </form>
        </div>
    </div>
</div>

<script>
    function utilityBills(cfg) {
        const csrf = () => (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
        return {
            billers: cfg.billers, limits: cfg.limits || {}, routes: cfg.routes,
            requiresInlineMomo: !!cfg.requiresInlineMomo, storeKey: cfg.storeKey,
            step: 1, biller: null, account: '', phone: '', result: null, token: null,
            meterId: '', confirmed: false, amount: '', payerPhone: '', payerNetwork: '',
            busy: false, waiting: false, error: '', fieldErrors: {}, intentKey: null,

            get canContinue() {
                if (!this.result) return false;
                if (this.result.meters.length && !this.meterId) return false;
                return this.confirmed;
            },
            get selectedName() {
                if (this.result && this.result.meters.length) {
                    const m = this.result.meters.find(x => x.id === this.meterId);
                    return m ? (m.name || 'Meter') : '';
                }
                return this.result ? (this.result.account_name || this.result.biller.label) : '';
            },
            get selectedMasked() {
                if (this.result && this.result.meters.length) {
                    const m = this.result.meters.find(x => x.id === this.meterId);
                    return m ? m.meter_masked : '';
                }
                return this.result ? this.result.account_masked : '';
            },

            chooseBiller(b) { this.biller = b; this.step = 2; this.error = ''; this.fieldErrors = {}; },
            reset() {
                this.step = 1; this.biller = null; this.account = ''; this.phone = ''; this.result = null;
                this.token = null; this.meterId = ''; this.confirmed = false; this.amount = ''; this.error = ''; this.fieldErrors = {};
            },

            async post(url, body) {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest'},
                    body: JSON.stringify(body),
                });
                let data = {};
                try { data = await res.json(); } catch (e) {}
                return {ok: res.ok, status: res.status, data};
            },
            applyErrors(r) {
                this.fieldErrors = {};
                if (r.status === 422 && r.data.errors) {
                    Object.keys(r.data.errors).forEach(k => this.fieldErrors[k] = r.data.errors[k][0]);
                    this.error = r.data.errors.lookup_token ? r.data.errors.lookup_token[0] : '';
                    if (!Object.keys(this.fieldErrors).length) this.error = r.data.message || 'Please check your details.';
                } else {
                    this.error = r.data.message || 'Something went wrong. Please try again.';
                }
            },

            async doLookup() {
                if (this.busy) return;
                this.busy = true; this.error = ''; this.fieldErrors = {};
                try {
                    const r = await this.post(this.routes.lookup, {biller: this.biller.key, account: this.account, phone: this.phone});
                    if (!r.ok || !r.data.success) { this.applyErrors(r); return; }
                    this.result = r.data; this.token = r.data.token; this.meterId = ''; this.confirmed = false;
                    this.amount = r.data.amount_owing && !r.data.meters.length ? r.data.amount_owing : '';
                    this.step = 3;
                } catch (e) { this.error = 'Network problem. Please try again.'; }
                finally { this.busy = false; }
            },

            async pay() {
                if (this.busy) return;
                this.busy = true; this.error = ''; this.fieldErrors = {};
                const fp = ['ub', this.storeKey, this.token, this.meterId, this.amount].join(':');
                this.intentKey = window.XtraCheckoutIntent ? window.XtraCheckoutIntent.getKey(fp) : null;
                try {
                    const r = await this.post(this.routes.checkout, {
                        lookup_token: this.token, selected_meter_id: this.meterId || null, amount: this.amount,
                        payer_phone: this.payerPhone || null, payer_network: this.payerNetwork || null,
                        idempotency_key: this.intentKey,
                    });
                    if (!r.ok || !r.data.success) { this.busy = false; this.applyErrors(r); return; }
                    const d = r.data;
                    if (d.status === 'success' && d.redirect) { window.location.href = d.redirect; return; }
                    if (d.flow_type === 'payaza' && d.checkout_config && window.InlinePaymentManager) {
                        window.InlinePaymentManager.openPayaza({reference: d.reference, checkout_config: d.checkout_config, verify_url: d.verify_url}, (s) => {
                            if (s !== 'paid') this.busy = false;
                        });
                        return;
                    }
                    if (d.redirect && !this.requiresInlineMomo) { window.location.href = d.redirect; return; }
                    if (d.reference) { this.waiting = true; this.poll(d.reference, d.verify_url, d.status_url); return; }
                    this.busy = false; this.error = 'Could not start the payment. Please try again.';
                } catch (e) { this.busy = false; this.error = 'Network problem. Please try again.'; }
            },

            poll(reference, verifyUrl, statusUrl) {
                let n = 0;
                const tick = async () => {
                    n++;
                    const r = await this.post(verifyUrl, {reference});
                    const s = r.data && r.data.status;
                    if (s === 'success' && r.data.redirect) { window.location.href = r.data.redirect; return; }
                    if (s === 'failed') {
                        if (window.XtraCheckoutIntent) window.XtraCheckoutIntent.clear(['ub', this.storeKey, this.token, this.meterId, this.amount].join(':'));
                        this.busy = false; this.waiting = false; this.error = r.data.message || 'Payment failed. Please try again.'; return;
                    }
                    if (n >= 60) { if (statusUrl) window.location.href = statusUrl; return; }
                    setTimeout(tick, 3000);
                };
                setTimeout(tick, 3000);
            },
        };
    }
</script>
@endsection
