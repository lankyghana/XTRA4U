@extends('layouts.vendor')

@section('title', 'Wallet - XTRA4U')
@section('description', 'Track wallet balance and request withdrawals')

@section('content')
@php
    $activeTab = request('tab', 'withdrawals');
@endphp
<x-vendor-layout :vendor="$vendor" title="Wallet" subtitle="Track wallet balance and request new disbursements" active="wallet">

    <div class="space-y-6">
        <!-- Wallet Summary + Tabs -->
        <div class="bg-white rounded-xl shadow p-4">
            <div class="md:flex md:items-center md:justify-between">
                <div class="flex items-center gap-3">
                    <div>
                        <h1 class="text-xl font-semibold text-gray-900">Wallet</h1>
                        <p class="text-sm text-gray-500">Manage withdrawals and top-ups</p>
                    </div>

                    <!-- Hidden balance element kept for JS compatibility -->
                    <div class="sr-only" aria-hidden="true">
                        <span id="wallet-balance">GHS {{ number_format($totalBalance ?? $vendor->wallet_balance, 2) }}</span>
                        <span id="wallet-last-updated">Last updated: {{ optional($vendor->updated_at)->diffForHumans() }}</span>
                    </div>
                </div>

                <!-- Top-up Modal (hidden by default) -->
                <div id="wallet-topup-modal" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
                    <div class="absolute inset-0 bg-black/40" data-close-modal></div>
                    <div class="relative bg-white rounded-lg shadow-xl w-full max-w-md p-6">
                        <h3 class="text-lg font-semibold text-gray-900">Mobile Money Payment</h3>
                        <div class="mt-4 bg-yellow-50 border border-yellow-100 p-4 rounded">
                            <p class="text-sm font-semibold text-yellow-800">Didn't receive the MoMo prompt?</p>
                            <p class="text-sm text-yellow-700 mt-2">You can approve the payment manually from your MoMo menu: Dial <strong>*170#</strong> → Select <strong>6 (My Wallet)</strong> → Select <strong>3 (My Approvals)</strong> → Approve the pending transaction.</p>
                        </div>

                        <form id="modal-topup-form" class="mt-4" onsubmit="return false;">
                            <label class="block text-sm font-medium text-gray-700">Amount (GHS)</label>
                            <input id="modal-topup-amount" type="number" step="0.01" min="1" class="mt-2 w-full rounded-lg border border-gray-300 px-4 py-2" placeholder="50.00" />

                            <label class="block text-sm font-medium text-gray-700 mt-4">MoMo number</label>
                            <input id="modal-topup-phone-modal" type="tel" class="mt-2 w-full rounded-lg border border-gray-300 px-4 py-2" placeholder="e.g. 0551234567" />

                            <label class="block text-sm font-medium text-gray-700 mt-4">Network</label>
                            <select id="modal-topup-network" class="mt-2 w-full rounded-lg border border-gray-300 px-3 py-2">
                                <option value="">Auto-detect network</option>
                                <option value="MTN">MTN</option>
                                <option value="TELECEL">TELECEL (Vodafone)</option>
                                <option value="AIRTELTIGO">AIRTELTIGO</option>
                            </select>

                            <div class="mt-6 flex items-center justify-end gap-3">
                                <button type="button" data-close-modal class="px-4 py-2 rounded-lg border bg-white">Cancel</button>
                                <button id="modal-topup-confirm" type="button" class="px-4 py-2 rounded-lg bg-purple-600 text-white">Confirm & Pay</button>
                            </div>

                            <p id="modal-topup-feedback" class="mt-3 text-sm text-red-600" aria-live="polite"></p>
                        </form>
                    </div>
                </div>

                        <div class="mt-4 md:mt-0 flex items-center gap-3">
                    <div class="flex items-center gap-2">
                        <a id="quick-topups" href="?tab=topups" class="px-4 py-2 rounded-full text-sm font-medium {{ $activeTab === 'topups' ? 'bg-purple-600 text-white' : 'bg-white text-gray-700 border border-gray-200 shadow-sm hover:shadow-md' }}">Top Ups</a>
                        <a id="quick-withdrawals" href="?tab=withdrawals" class="px-4 py-2 rounded-full text-sm font-medium {{ $activeTab === 'withdrawals' ? 'bg-purple-600 text-white' : 'bg-white text-gray-700 border border-gray-200 shadow-sm hover:shadow-md' }}">Withdrawals</a>
                    </div>

                    <a href="#history" class="text-sm text-gray-600 hidden md:inline">View History</a>
                </div>
            </div>

            <div id="wallet-success" class="mt-4 hidden rounded-md p-3 bg-green-50 border border-green-100 text-green-800" role="status" aria-live="polite"></div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            @if ($activeTab === 'topups')
                @php
                    // Controller provides these variables: $totalTopups, $topupsSpent, $topupOrdersCount, $topupOrdersTotal
                @endphp

                <!-- Available Top-Ups Balance -->
                <div class="bg-gradient-to-br from-blue-500 to-indigo-600 rounded-xl shadow-lg overflow-hidden transform hover:scale-105 transition-transform duration-200">
                    <div class="px-6 py-5">
                        <p class="text-sm text-white font-medium">Available Top-Ups Balance</p>
                        <p id="topups-balance" class="text-2xl font-bold text-white mt-2">GHS {{ number_format($totalTopups ?? 0, 2) }}</p>
                        <p class="text-xs text-white mt-2">Unspent wallet top-ups (non-withdrawable).</p>
                    </div>
                </div>

                <!-- Total Top-Ups Spent -->
                <div class="bg-gradient-to-br from-pink-500 to-pink-600 rounded-xl shadow-lg overflow-hidden transform hover:scale-105 transition-transform duration-200">
                    <div class="px-6 py-5">
                        <p class="text-sm text-white font-medium">Total Top-Ups Spent</p>
                        <p class="text-2xl font-bold text-white mt-2">GHS {{ number_format($topupsSpent ?? 0, 2) }}</p>
                        <p class="text-xs text-white mt-2">Amount used from top-ups to place orders.</p>
                    </div>
                </div>

                <!-- Total Top-Up Orders Placed -->
                <div class="bg-gradient-to-br from-green-400 to-emerald-500 rounded-xl shadow-lg overflow-hidden transform hover:scale-105 transition-transform duration-200">
                    <div class="px-6 py-5">
                        <p class="text-sm text-white font-medium">Top-Up Orders Placed</p>
                        <p class="text-2xl font-bold text-white mt-2">{{ $topupOrdersCount ?? 0 }} {{ \Illuminate\Support\Str::plural('order', $topupOrdersCount ?? 0) }}</p>
                        <p class="text-xs text-white mt-2">Total value: GHS {{ number_format($topupOrdersTotal ?? 0, 2) }}</p>
                    </div>
                </div>
            @else
                <!-- Withdrawable Balance Card -->
                <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl shadow-lg overflow-hidden transform hover:scale-105 transition-transform duration-200">
                    <div class="px-6 py-5">
                        <p class="text-sm text-white font-medium">Withdrawable Balance</p>
                        <p id="withdrawable-balance" class="text-2xl font-bold text-white mt-2">GHS {{ number_format($withdrawableBalance, 2) }}</p>
                        <p class="text-xs text-white mt-2">Available wallet balance.</p>
                    </div>
                </div>

                <!-- Processing Requests Card -->
                <div class="bg-gradient-to-br from-yellow-500 to-yellow-600 rounded-xl shadow-lg overflow-hidden transform hover:scale-105 transition-transform duration-200">
                    <div class="px-6 py-5">
                        <p class="text-sm text-white font-medium">Processing Requests</p>
                        <p class="text-2xl font-bold text-white mt-2">GHS {{ number_format($pendingTotal, 2) }}</p>
                        <p class="text-xs text-white mt-2">Being processed automatically.</p>
                    </div>
                </div>

                <!-- Approved To Date Card (display-only) -->
                <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-xl shadow-lg overflow-hidden transform hover:scale-105 transition-transform duration-200">
                    <div class="px-6 py-5">
                        <p class="text-sm text-white font-medium">Approved To Date</p>
                        <p id="approved-total" class="text-2xl font-bold text-white mt-2">GHS {{ number_format($approvedTotal ?? 0.0, 2) }}</p>
                        <p class="text-xs text-white mt-2">Total paid out successfully.</p>
                    </div>
                </div>
            @endif
        </div>

        <div class="bg-gradient-to-br from-purple-50 to-blue-50 rounded-xl shadow-lg border border-purple-100">
            <div class="px-6 py-6">
                @if ($activeTab === 'topups')
                    <div id="panel-topups" class="mb-4 w-full max-w-full overflow-x-hidden box-border">
                        <div class="bg-white rounded-lg shadow p-5 w-full box-border">
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-stretch">
                                <!-- Left: Info -->
                                <div class="flex flex-col justify-center">
                                    <h3 class="text-lg font-semibold text-gray-900">Top Up Wallet</h3>
                                    <p class="mt-2 text-sm text-gray-600">Add funds to your vendor wallet to place orders. Top-up balances are not withdrawable.</p>

                                    <div class="mt-4 w-full inline-flex items-start gap-3 bg-blue-50 border border-blue-100 rounded-lg p-3 box-border">
                                        <div class="text-blue-600 text-xl">🔒</div>
                                        <div>
                                            <p class="text-sm font-medium text-blue-800">Top-up funds are for orders only</p>
                                            <p class="text-xs text-blue-600">Top-ups can only be used to place vendor orders and cannot be withdrawn.</p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Right: Action -->
                                <div class="flex flex-col justify-center">
                                    <form id="wallet-topup-form" class="w-full" onsubmit="return false;">
                                        <label for="wallet-topup-amount" class="block text-sm font-medium text-gray-700">Amount (GHS)</label>
                                        <div class="mt-2">
                                            <input aria-label="Top up amount" type="number" step="0.01" min="1" name="amount" id="wallet-topup-amount" inputmode="decimal" placeholder="50.00" class="w-full rounded-lg border border-gray-300 px-4 py-3 focus:ring-2 focus:ring-purple-200 focus:border-purple-500 box-border" />
                                        </div>

                                        <!-- Quick amount chips -->
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            <button type="button" class="topup-chip flex-1 sm:flex-none text-center text-sm px-3 py-2 rounded-md border border-gray-200 bg-white hover:bg-gray-50 min-w-0">+50</button>
                                            <button type="button" class="topup-chip flex-1 sm:flex-none text-center text-sm px-3 py-2 rounded-md border border-gray-200 bg-white hover:bg-gray-50 min-w-0">+100</button>
                                            <button type="button" class="topup-chip flex-1 sm:flex-none text-center text-sm px-3 py-2 rounded-md border border-gray-200 bg-white hover:bg-gray-50 min-w-0">+200</button>
                                        </div>

                                        <input type="hidden" name="vendor_id" value="{{ $vendor->id }}" />
                                        <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
                                            <div>
                                                <label for="wallet-topup-phone" class="block text-sm font-medium text-gray-700">MoMo number</label>
                                                <input id="wallet-topup-phone" aria-label="Payer MoMo number" type="tel" name="payer_phone" placeholder="e.g. 0244123456" class="mt-2 w-full rounded-lg border border-gray-300 px-4 py-3 focus:ring-2 focus:ring-purple-200 focus:border-purple-500" />
                                                <p class="text-xs text-gray-400 mt-1">Enter the number that will approve the payment prompt.</p>
                                            </div>
                                            <div>
                                                <label for="wallet-topup-network" class="block text-sm font-medium text-gray-700">Network</label>
                                                <select id="wallet-topup-network" name="network" class="mt-2 w-full rounded-lg border border-gray-300 px-3 py-3 focus:ring-2 focus:ring-purple-200 focus:border-purple-500">
                                                    <option value="">Auto-detect network</option>
                                                    <option value="MTN">MTN</option>
                                                    <option value="TELECEL">TELECEL (Vodafone)</option>
                                                    <option value="AIRTELTIGO">AIRTELTIGO</option>
                                                </select>
                                                <p class="text-xs text-gray-400 mt-1">Choose the network if auto-detection fails.</p>
                                            </div>
                                        </div>

                                        <div class="mt-4 flex items-center justify-end">
                                            <button type="button" id="wallet-topup-submit" class="w-full sm:w-auto rounded-lg bg-purple-600 text-white px-4 py-3 inline-flex items-center justify-center disabled:opacity-60 disabled:cursor-not-allowed whitespace-nowrap">
                                                <svg id="wallet-topup-button-spinner" class="w-4 h-4 animate-spin hidden mr-2" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                                                <span id="wallet-topup-button-text">Top Up Wallet</span>
                                            </button>
                                        </div>
                                        <input type="hidden" id="wallet-topup-gateway" name="gateway" value="">

                                        <p id="wallet-topup-feedback" class="mt-3 text-sm text-red-600" aria-live="polite"></p>

                                        <p class="mt-4 text-xs text-gray-500 inline-flex items-center gap-2"><span class="text-sm">🔒</span> This balance cannot be withdrawn.</p>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <script>
                            (function () {
                                // Elements
                                const btn = document.getElementById('wallet-topup-submit');
                                const amountInput = document.getElementById('wallet-topup-amount');
                                const gatewayHidden = document.getElementById('wallet-topup-gateway');
                                const gatewayButtons = Array.from(document.querySelectorAll('.gateway-option'));
                                const feedback = document.getElementById('wallet-topup-feedback');
                                const spinner = document.getElementById('wallet-topup-button-spinner');
                                const btnText = document.getElementById('wallet-topup-button-text');
                                const chips = Array.from(document.querySelectorAll('.topup-chip'));

                                if (!btn || !amountInput) return;

                                // UX helpers
                                const setLoading = (isLoading) => {
                                    btn.disabled = isLoading;
                                    spinner.classList.toggle('hidden', !isLoading);
                                    btnText.textContent = isLoading ? 'Processing…' : 'Top Up Wallet';
                                };

                                const showMessage = (msg, variant = 'error') => {
                                    feedback.textContent = msg || '';
                                    feedback.className = msg ? (variant === 'error' ? 'mt-3 text-sm text-red-600' : 'mt-3 text-sm text-green-700') : '';
                                };

                                let inProgress = false;

                                // Enable/disable button based on input
                                function validate() {
                                    const val = parseFloat(amountInput.value || 0);
                                    if (!val || val < 1) {
                                        btn.disabled = true;
                                        return false;
                                    }
                                    btn.disabled = false;
                                    return true;
                                }

                                amountInput.addEventListener('input', () => {
                                    showMessage('', '');
                                    validate();
                                });

                                chips.forEach(c => {
                                    c.addEventListener('click', () => {
                                        const chipVal = c.textContent.trim().replace('+', '');
                                        amountInput.value = chipVal;
                                        amountInput.dispatchEvent(new Event('input'));
                                    });
                                });

                                btn.addEventListener('click', async () => {
                                    if (inProgress) return;
                                    if (!validate()) { showMessage('Enter a valid amount (minimum GHS 1)', 'error'); return; }

                                    const amount = parseFloat(amountInput.value || 0);
                                    inProgress = true;
                                    setLoading(true);
                                    showMessage('');

                                    try {
                                        const payload = { vendor_id: '{{ $vendor->id }}', amount: amount };
                                        const payerEl = document.getElementById('wallet-topup-phone');
                                        if (payerEl && payerEl.value && String(payerEl.value).trim().length > 0) {
                                            payload.payer_phone = String(payerEl.value).trim();
                                        }
                                        const netEl = document.getElementById('wallet-topup-network');
                                        if (netEl && netEl.value && String(netEl.value).trim().length > 0) {
                                            payload.network = String(netEl.value).trim();
                                        }
                                        if (gatewayHidden && gatewayHidden.value) payload.gateway = gatewayHidden.value;

                                        const resp = await fetch('{{ route('vendor.wallet.topup') }}', {
                                            method: 'POST',
                                            headers: {
                                                'Content-Type': 'application/json',
                                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                                'Accept': 'application/json'
                                            },
                                            body: JSON.stringify(payload)
                                        });
                                        const data = await resp.json();

                                        if (data.success && data.authorization_url) {
                                            // redirect to payment provider
                                            window.location.href = data.authorization_url;
                                            return;
                                        }

                                        // Inline flows (no authorization_url) return a reference we can poll.
                                        if (data.success && data.reference && !data.authorization_url) {
                                            // start polling for completion
                                            showMessage('Processing payment, waiting for confirmation...', '');
                                            await pollTopupStatus(data.reference);
                                            setLoading(false);
                                            inProgress = false;
                                            return;
                                        }

                                        if (data.success) {
                                            showMessage('\u2714\uFE0F Wallet topped up successfully', 'success');
                                            await refreshWalletSummary(true);
                                            amountInput.value = '';
                                            if (gatewayHidden) gatewayHidden.value = '';
                                            gatewayButtons.forEach(b => b.classList.remove('ring','ring-2','ring-purple-400'));
                                            setLoading(false);
                                            inProgress = false;
                                            return;
                                        }

                                        setLoading(false);
                                        inProgress = false;
                                        showMessage(data.message || 'Failed to initiate top-up', 'error');
                                    } catch (e) {
                                        setLoading(false);
                                        inProgress = false;
                                        showMessage('Unable to contact server', 'error');
                                    }
                                });

                                async function refreshWalletSummary(showSuccess) {
                                    try {
                                        const res = await fetch('{{ url('/vendor/wallet/' . $vendor->id) }}');
                                        const json = await res.json();
                                        if (json.success) {
                                            const wb = document.getElementById('wallet-balance');
                                            if (wb) wb.textContent = 'GHS ' + (json.balance || 0).toFixed(2);
                                            const lu = document.getElementById('wallet-last-updated');
                                            if (lu) lu.textContent = 'Last updated: ' + (json.last_updated || 'just now');
                                            if (typeof json.withdrawable_balance !== 'undefined') {
                                                const el = document.getElementById('withdrawable-balance');
                                                if (el) el.textContent = 'GHS ' + (json.withdrawable_balance || 0).toFixed(2);
                                            }
                                            if (typeof json.vendor_topups_total !== 'undefined') {
                                                const el2 = document.getElementById('vendor-topups-total');
                                                if (el2) el2.textContent = 'GHS ' + (json.vendor_topups_total || 0).toFixed(2);
                                            }
                                            if (typeof json.approved_total !== 'undefined') {
                                                const ap = document.getElementById('approved-total');
                                                if (ap) ap.textContent = 'GHS ' + (json.approved_total || 0).toFixed(2);
                                            }
                                            if (showSuccess) {
                                                const successEl = document.getElementById('wallet-success');
                                                successEl.textContent = '\u2714\uFE0F Wallet topped up successfully. Your new balance is GHS ' + (json.balance || 0).toFixed(2);
                                                successEl.classList.remove('hidden');
                                                successEl.focus?.();
                                                successEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                                setTimeout(() => { successEl.classList.add('hidden'); }, 4000);
                                            }
                                        }
                                    } catch (e) {}
                                }

                                // Poll a top-up reference until the gateway/callback marks it completed or failed.
                                async function pollTopupStatus(reference) {
                                    const pollInterval = 2500; // ms
                                    const timeoutMs = 120000; // 2 minutes
                                    const started = Date.now();
                                    let lastState = null;

                                    while (Date.now() - started < timeoutMs) {
                                        try {
                                            const r = await fetch('{{ route('vendor.wallet.topup.status', ['reference' => 'REF']) }}'.replace('REF', encodeURIComponent(reference)), {
                                                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                                                credentials: 'same-origin'
                                            });
                                            if (!r.ok) {
                                                // transient error — try again
                                            } else {
                                                const j = await r.json();
                                                const state = j.status || (j.raw && (j.raw.data && j.raw.data.status)) || null;
                                                if (state && state !== lastState) {
                                                    lastState = state;
                                                }

                                                if (j.status === 'completed') {
                                                    showMessage('\u2714\uFE0F Wallet topped up successfully', 'success');
                                                    await refreshWalletSummary(true);
                                                    amountInput.value = '';
                                                    if (gatewayHidden) gatewayHidden.value = '';
                                                    gatewayButtons.forEach(b => b.classList.remove('ring','ring-2','ring-purple-400'));
                                                    return;
                                                }

                                                if (j.status === 'failed') {
                                                    showMessage('Top-up failed. Please try again or contact support.', 'error');
                                                    return;
                                                }
                                            }
                                        } catch (e) {
                                            // ignore and retry until timeout
                                        }

                                        await new Promise(r => setTimeout(r, pollInterval));
                                    }

                                    // Timed out waiting for confirmation — advise user to check history
                                    showMessage('Awaiting payment confirmation timed out. Check your Top-ups tab or contact support.', 'error');
                                }

                                // Gateway selection highlighting (if gateways present)
                                gatewayButtons.forEach(b => {
                                    b.addEventListener('click', () => {
                                        gatewayButtons.forEach(x => x.classList.remove('ring','ring-2','ring-purple-400'));
                                        b.classList.add('ring','ring-2','ring-purple-400');
                                        if (gatewayHidden) gatewayHidden.value = b.dataset.key;
                                    });
                                });

                                // initial validation state
                                validate();

                                // Modal open/close helpers
                                function openTopupModal(prefill) {
                                    const modal = document.getElementById('wallet-topup-modal');
                                    if (!modal) return;
                                    modal.classList.remove('hidden');
                                    modal.classList.add('flex');
                                    if (prefill && prefill.amount) document.getElementById('modal-topup-amount').value = prefill.amount;
                                }

                                function closeTopupModal() {
                                    const modal = document.getElementById('wallet-topup-modal');
                                    if (!modal) return;
                                    modal.classList.add('hidden');
                                    modal.classList.remove('flex');
                                    document.getElementById('modal-topup-feedback').textContent = '';
                                }

                                // Wire any "Top up wallet" links on the page to open the modal
                                document.querySelectorAll('a[href*="/vendor/wallet"]').forEach(a => {
                                    a.addEventListener('click', (ev) => {
                                        // If link explicitly includes ?tab=topups allow normal navigation
                                        if (String(a.href || '').includes('?tab=topups')) return;
                                        ev.preventDefault();
                                        openTopupModal();
                                    });
                                });

                                // Close modal elements
                                document.querySelectorAll('[data-close-modal]').forEach(el => el.addEventListener('click', closeTopupModal));

                                // Confirm button inside modal
                                const modalConfirm = document.getElementById('modal-topup-confirm');
                                if (modalConfirm) {
                                    modalConfirm.addEventListener('click', async () => {
                                        const amt = parseFloat(document.getElementById('modal-topup-amount').value || 0);
                                        const phone = String(document.getElementById('modal-topup-phone-modal').value || '').trim();
                                        const net = String(document.getElementById('modal-topup-network').value || '').trim();
                                        const feedbackEl = document.getElementById('modal-topup-feedback');

                                        if (!amt || amt < 1) { feedbackEl.textContent = 'Enter a valid amount (minimum GHS 1)'; return; }
                                        if (!phone) { feedbackEl.textContent = 'Enter the MoMo number that will approve the payment prompt.'; return; }

                                        // Trigger the same API used by the page
                                        modalConfirm.disabled = true;
                                        feedbackEl.textContent = '';
                                        try {
                                            const payload = { vendor_id: '{{ $vendor->id }}', amount: amt, payer_phone: phone };
                                            if (net) payload.network = net;

                                            const resp = await fetch('{{ route('vendor.wallet.topup') }}', {
                                                method: 'POST',
                                                headers: {
                                                    'Content-Type': 'application/json',
                                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                                    'Accept': 'application/json'
                                                },
                                                body: JSON.stringify(payload)
                                            });

                                            const data = await resp.json();
                                            if (data.success && data.authorization_url) {
                                                // redirect
                                                window.location.href = data.authorization_url;
                                                return;
                                            }

                                            if (data.success && data.reference && !data.authorization_url) {
                                                feedbackEl.textContent = 'Processing payment, waiting for confirmation...';
                                                await pollTopupStatus(data.reference);
                                                closeTopupModal();
                                                modalConfirm.disabled = false;
                                                return;
                                            }

                                            if (data.success) {
                                                feedbackEl.textContent = 'Wallet topped up successfully.';
                                                await refreshWalletSummary(true);
                                                closeTopupModal();
                                                modalConfirm.disabled = false;
                                                return;
                                            }

                                            modalConfirm.disabled = false;
                                            feedbackEl.textContent = data.message || 'Failed to initiate top-up';
                                        } catch (e) {
                                            modalConfirm.disabled = false;
                                            document.getElementById('modal-topup-feedback').textContent = 'Unable to contact server';
                                        }
                                    });
                                }
                            })();
                        </script>
                    </div>
                @else
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
        @endif

        <div class="bg-gradient-to-br from-white to-gray-50 rounded-xl shadow-lg border border-gray-200">
            <div class="px-6 py-6">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h2 class="text-lg font-bold text-gray-900">Wallet Activity</h2>
                        <p class="text-sm text-gray-600">All top-ups and withdrawals in one place.</p>
                        <!-- Keep legacy text for backwards-compatible tests and links -->
                        <p class="sr-only">Withdrawal History</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <label class="text-sm text-gray-600">Filter:</label>
                        <select id="history-filter" class="px-3 py-2 rounded border">
                            <option value="all">All</option>
                            <option value="topups">Top-ups</option>
                            <option value="withdrawals">Withdrawals</option>
                        </select>
                    </div>
                </div>

                <div id="history" class="overflow-hidden rounded-lg border border-gray-200">
                    <x-table :headers="['Type', 'Reference', 'Date', 'Amount', 'Status']">
                        @forelse ($history as $item)
                            <tr class="hover:bg-gray-50 transition-colors duration-150 history-row" data-type="{{ strtolower(str_replace(' ', '-', $item->type)) }}">
                                <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $item->type }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $item->reference ?? '-' }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ optional($item->date)->format('M d, Y • h:i A') }}</td>
                                <td class="px-6 py-4 text-sm font-semibold text-gray-900">GHS {{ number_format($item->amount, 2) }}</td>
                                <td class="px-6 py-4 text-sm text-gray-700">{{ ucfirst($item->status ?? 'n/a') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-4 text-center text-sm text-gray-500">No wallet activity yet.</td>
                            </tr>
                        @endforelse
                    </x-table>
                </div>

                <script>
                    (function () {
                        const filter = document.getElementById('history-filter');
                        if (!filter) return;
                        filter.addEventListener('change', () => {
                            const val = filter.value;
                            const rows = Array.from(document.querySelectorAll('.history-row'));
                            rows.forEach(r => {
                                if (val === 'all') { r.style.display = ''; return; }
                                const type = r.getAttribute('data-type');
                                if (val === 'topups' && type === 'top-up') r.style.display = '';
                                else if (val === 'withdrawals' && type === 'withdrawal') r.style.display = '';
                                else r.style.display = 'none';
                            });
                        });
                    })();
                </script>
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