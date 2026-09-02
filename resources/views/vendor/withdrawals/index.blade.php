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
                                <button id="modal-topup-confirm" type="button" class="px-4 py-2 rounded-lg bg-brand-violet text-white">Confirm & Pay</button>
                            </div>

                            <p id="modal-topup-feedback" class="mt-3 text-sm text-red-600" aria-live="polite"></p>
                        </form>
                    </div>
                </div>

                <!-- ===== DEPOSIT SUCCESS MODAL ===== -->
                <div id="deposit-success-modal" class="fixed inset-0 z-[60] hidden items-center justify-center p-4" role="dialog" aria-modal="true" aria-label="Deposit successful">
                    <!-- Backdrop -->
                    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" id="deposit-success-backdrop"></div>

                    <!-- Confetti canvas -->
                    <canvas id="confetti-canvas" class="absolute inset-0 pointer-events-none z-10" style="width:100%;height:100%;"></canvas>

                    <!-- Card -->
                    <div id="deposit-success-card" class="relative z-20 bg-white rounded-2xl shadow-2xl w-full max-w-sm p-8 text-center"
                         style="transform:scale(0.85) translateY(24px);opacity:0;transition:transform 0.4s cubic-bezier(.34,1.56,.64,1),opacity 0.3s ease;">

                        <!-- Animated checkmark ring -->
                        <div class="mx-auto mb-5" style="width:80px;height:80px;position:relative;">
                            <svg viewBox="0 0 80 80" class="absolute inset-0" style="width:80px;height:80px;">
                                <circle cx="40" cy="40" r="36" fill="none" stroke="#e9d5ff" stroke-width="7"/>
                                <circle id="success-ring" cx="40" cy="40" r="36" fill="none" stroke="#533afd" stroke-width="7"
                                        stroke-linecap="round"
                                        stroke-dasharray="226" stroke-dashoffset="226"
                                        style="transform:rotate(-90deg);transform-origin:center;transition:stroke-dashoffset 0.7s cubic-bezier(.4,0,.2,1) 0.15s;"/>
                            </svg>
                            <!-- Checkmark icon -->
                            <div id="success-check" class="absolute inset-0 flex items-center justify-center"
                                 style="opacity:0;transform:scale(0.5);transition:opacity 0.3s ease 0.6s,transform 0.3s cubic-bezier(.34,1.56,.64,1) 0.6s;">
                                <svg viewBox="0 0 24 24" fill="none" stroke="#533afd" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:36px;height:36px;">
                                    <polyline points="20 6 9 17 4 12"/>
                                </svg>
                            </div>
                        </div>

                        <p class="text-sm font-semibold tracking-widest text-violet-500 uppercase mb-1">Deposit Successful</p>
                        <h2 class="text-3xl font-extrabold text-gray-900 mb-1" id="success-amount-display">GHS 0.00</h2>
                        <p class="text-sm text-gray-500 mb-5">has been added to your wallet</p>

                        <div class="bg-gradient-to-r from-violet-50 to-indigo-50 rounded-xl px-5 py-4 mb-6 border border-violet-100">
                            <p class="text-xs text-violet-600 font-medium mb-1">New Top-Up Balance</p>
                            <p class="text-xl font-bold text-violet-900" id="success-balance-display">GHS —</p>
                        </div>

                        <div class="flex flex-col gap-2">
                            <button id="deposit-success-close" type="button"
                                class="w-full rounded-xl bg-brand-violet hover:bg-brand-violet-deep active:bg-brand-violet-press text-white font-semibold py-3 transition-colors">
                                Great, thanks! 🎉
                            </button>
                            <button type="button" onclick="window.location.href='?tab=topups'" 
                                class="w-full rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium py-3 text-sm transition-colors">
                                View balance details
                            </button>
                        </div>
                    </div>
                </div>

                <style>
                #deposit-success-modal.is-open { display: flex; }
                #deposit-success-modal.is-open #deposit-success-card {
                    transform: scale(1) translateY(0) !important;
                    opacity: 1 !important;
                }
                </style>

                <script>
                (function(){
                    /* ---- Confetti engine ---- */
                    function launchConfetti() {
                        const canvas = document.getElementById('confetti-canvas');
                        if (!canvas) return;
                        canvas.width = window.innerWidth;
                        canvas.height = window.innerHeight;
                        const ctx = canvas.getContext('2d');
                        const colors = ['#533afd','#a78bfa','#f472b6','#34d399','#fbbf24','#60a5fa','#f87171'];
                        const pieces = Array.from({length: 90}, () => ({
                            x: Math.random() * canvas.width,
                            y: Math.random() * canvas.height * 0.3 - canvas.height * 0.2,
                            r: Math.random() * 7 + 3,
                            d: Math.random() * 80 + 60,
                            color: colors[Math.floor(Math.random() * colors.length)],
                            tilt: Math.floor(Math.random() * 10) - 10,
                            tiltAngleIncrement: (Math.random() * 0.07 + 0.05),
                            tiltAngle: 0,
                            vy: Math.random() * 3 + 2,
                            vx: Math.random() * 4 - 2,
                            opacity: 1,
                        }));
                        let frame;
                        let startTime = null;
                        const duration = 3200;
                        function draw(ts) {
                            if (!startTime) startTime = ts;
                            const elapsed = ts - startTime;
                            ctx.clearRect(0, 0, canvas.width, canvas.height);
                            pieces.forEach(p => {
                                p.tiltAngle += p.tiltAngleIncrement;
                                p.y += p.vy;
                                p.x += p.vx;
                                p.tilt = Math.sin(p.tiltAngle) * 12;
                                p.opacity = Math.max(0, 1 - (elapsed / duration));
                                ctx.beginPath();
                                ctx.lineWidth = p.r;
                                ctx.strokeStyle = p.color;
                                ctx.globalAlpha = p.opacity;
                                ctx.moveTo(p.x + p.tilt + p.r / 3, p.y);
                                ctx.lineTo(p.x + p.tilt, p.y + p.tilt + p.r / 5);
                                ctx.stroke();
                            });
                            ctx.globalAlpha = 1;
                            if (elapsed < duration) {
                                frame = requestAnimationFrame(draw);
                            } else {
                                ctx.clearRect(0, 0, canvas.width, canvas.height);
                            }
                        }
                        if (frame) cancelAnimationFrame(frame);
                        frame = requestAnimationFrame(draw);
                    }

                    /* ---- Show success modal ---- */
                    window.showDepositSuccessModal = function(amount, newBalance) {
                        const modal = document.getElementById('deposit-success-modal');
                        const amountEl = document.getElementById('success-amount-display');
                        const balanceEl = document.getElementById('success-balance-display');
                        const ring = document.getElementById('success-ring');
                        const check = document.getElementById('success-check');
                        if (!modal) return;

                        // Fill in values
                        amountEl.textContent = 'GHS ' + parseFloat(amount || 0).toFixed(2);
                        balanceEl.textContent = newBalance != null ? 'GHS ' + parseFloat(newBalance).toFixed(2) : 'GHS —';

                        // Reset animations
                        ring.style.strokeDashoffset = '226';
                        check.style.opacity = '0';
                        check.style.transform = 'scale(0.5)';

                        // Show modal
                        modal.classList.add('is-open');

                        // Trigger ring animation
                        requestAnimationFrame(() => {
                            requestAnimationFrame(() => {
                                ring.style.strokeDashoffset = '0';
                                setTimeout(() => {
                                    check.style.opacity = '1';
                                    check.style.transform = 'scale(1)';
                                }, 620);
                            });
                        });

                        launchConfetti();
                    };

                    /* ---- Close handler ---- */
                    function closeDepositSuccess() {
                        const modal = document.getElementById('deposit-success-modal');
                        if (!modal) return;
                        modal.classList.remove('is-open');
                    }
                    document.getElementById('deposit-success-close')?.addEventListener('click', closeDepositSuccess);
                    document.getElementById('deposit-success-backdrop')?.addEventListener('click', closeDepositSuccess);
                })();
                </script>

                        <div class="mt-4 md:mt-0 flex items-center gap-3">
                    <div class="flex items-center gap-2">
                        <a id="quick-topups" href="?tab=topups" class="px-4 py-2 rounded-full text-sm font-medium {{ $activeTab === 'topups' ? 'bg-brand-violet text-white' : 'bg-white text-gray-700 border border-gray-200 shadow-sm hover:shadow-md' }}">Top Ups</a>
                        <a id="quick-withdrawals" href="?tab=withdrawals" class="px-4 py-2 rounded-full text-sm font-medium {{ $activeTab === 'withdrawals' ? 'bg-brand-violet text-white' : 'bg-white text-gray-700 border border-gray-200 shadow-sm hover:shadow-md' }}">Withdrawals</a>
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
                <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <div class="px-6 py-5">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Available Top-Ups Balance</p>
                        <p id="topups-balance" class="text-2xl font-bold text-gray-900 mt-2">GHS {{ number_format($totalTopups ?? 0, 2) }}</p>
                        <p class="text-xs text-gray-400 mt-2">Unspent wallet top-ups (non-withdrawable).</p>
                    </div>
                </div>

                <!-- Total Top-Ups Spent -->
                <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <div class="px-6 py-5">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Total Top-Ups Spent</p>
                        <p class="text-2xl font-bold text-gray-900 mt-2">GHS {{ number_format($topupsSpent ?? 0, 2) }}</p>
                        <p class="text-xs text-gray-400 mt-2">Amount used from top-ups to place orders.</p>
                    </div>
                </div>

                <!-- Total Top-Up Orders Placed -->
                <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <div class="px-6 py-5">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Top-Up Orders Placed</p>
                        <p class="text-2xl font-bold text-gray-900 mt-2">{{ $topupOrdersCount ?? 0 }} {{ \Illuminate\Support\Str::plural('order', $topupOrdersCount ?? 0) }}</p>
                        <p class="text-xs text-gray-400 mt-2">Total value: GHS {{ number_format($topupOrdersTotal ?? 0, 2) }}</p>
                    </div>
                </div>
            @else
                <!-- Withdrawable Balance Card -->
                <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <div class="px-6 py-5">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Withdrawable Balance</p>
                        <p id="withdrawable-balance" class="text-2xl font-bold text-gray-900 mt-2">GHS {{ number_format($withdrawableBalance, 2) }}</p>
                        <p class="text-xs text-gray-400 mt-2">Available wallet balance.</p>
                    </div>
                </div>

                <!-- Processing Requests Card -->
                <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <div class="px-6 py-5">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Processing Requests</p>
                        <p class="text-2xl font-bold text-gray-900 mt-2">GHS {{ number_format($pendingTotal, 2) }}</p>
                        <p class="text-xs text-gray-400 mt-2">Being processed automatically.</p>
                    </div>
                </div>

                <!-- Approved To Date Card (display-only) -->
                <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <div class="px-6 py-5">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Approved To Date</p>
                        <p id="approved-total" class="text-2xl font-bold text-gray-900 mt-2">GHS {{ number_format($approvedTotal ?? 0.0, 2) }}</p>
                        <p class="text-xs text-gray-400 mt-2">Total paid out successfully.</p>
                    </div>
                </div>
            @endif
        </div>

        <div class="bg-white rounded-xl border border-gray-200">
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
                                            <input aria-label="Top up amount" type="number" step="0.01" min="1" name="amount" id="wallet-topup-amount" inputmode="decimal" placeholder="50.00" class="w-full rounded-lg border border-gray-300 px-4 py-3 focus:ring-2 focus:ring-violet-200 focus:border-violet-500 box-border" />
                                        </div>

                                        <!-- Quick amount chips -->
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            <button type="button" class="topup-chip flex-1 sm:flex-none text-center text-sm px-3 py-2 rounded-md border border-gray-200 bg-white hover:bg-gray-50 min-w-0">+50</button>
                                            <button type="button" class="topup-chip flex-1 sm:flex-none text-center text-sm px-3 py-2 rounded-md border border-gray-200 bg-white hover:bg-gray-50 min-w-0">+100</button>
                                            <button type="button" class="topup-chip flex-1 sm:flex-none text-center text-sm px-3 py-2 rounded-md border border-gray-200 bg-white hover:bg-gray-50 min-w-0">+200</button>
                                        </div>

                                        <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
                                            <div>
                                                <label for="wallet-topup-phone" class="block text-sm font-medium text-gray-700">MoMo number</label>
                                                <input id="wallet-topup-phone" aria-label="Payer MoMo number" type="tel" name="payer_phone" placeholder="e.g. 0244123456" class="mt-2 w-full rounded-lg border border-gray-300 px-4 py-3 focus:ring-2 focus:ring-violet-200 focus:border-violet-500" />
                                                <p class="text-xs text-gray-400 mt-1">Enter the number that will approve the payment prompt.</p>
                                            </div>
                                            <div>
                                                <label for="wallet-topup-network" class="block text-sm font-medium text-gray-700">Network</label>
                                                <select id="wallet-topup-network" name="network" class="mt-2 w-full rounded-lg border border-gray-300 px-3 py-3 focus:ring-2 focus:ring-violet-200 focus:border-violet-500">
                                                    <option value="">Auto-detect network</option>
                                                    <option value="MTN">MTN</option>
                                                    <option value="TELECEL">TELECEL (Vodafone)</option>
                                                    <option value="AIRTELTIGO">AIRTELTIGO</option>
                                                </select>
                                                <p class="text-xs text-gray-400 mt-1">Choose the network if auto-detection fails.</p>
                                            </div>
                                        </div>

                                        <div class="mt-4 flex items-center justify-end">
                                            <button type="button" id="wallet-topup-submit" class="w-full sm:w-auto rounded-lg bg-brand-violet text-white px-4 py-3 inline-flex items-center justify-center disabled:opacity-60 disabled:cursor-not-allowed whitespace-nowrap">
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

                                        // Use shared InlinePaymentManager for inline flows
                                        if (data.flow_type === 'inline') {
                                            InlinePaymentManager.open({
                                                reference: data.reference,
                                                authorization_url: data.authorization_url ?? null,
                                                gateway_name: data.gateway_name ?? null,
                                                flow_type: data.payment_type === 'wallet_topup' ? 'wallet_topup' : 'checkout',
                                                poll_url: '{{ route('vendor.wallet.topup.status', ['reference' => 'REF']) }}'.replace('REF', encodeURIComponent(data.reference)),
                                                no_redirect: true
                                            }, async (status) => {
                                                if (status === 'paid' || status === 'completed') {
                                                    const summary = await refreshWalletSummary(false);
                                                    amountInput.value = '';
                                                    if (gatewayHidden) gatewayHidden.value = '';
                                                    gatewayButtons.forEach(b => b.classList.remove('ring','ring-2','ring-violet-400'));
                                                    showDepositSuccessModal(amount, summary?.topupBalance);
                                                } else if (status === 'failed') {
                                                    showMessage('Top-up failed. Please try again or contact support.', 'error');
                                                }
                                            });
                                            if (data.reference) {
                                                showMessage('Payment initiated. Waiting for confirmation...', 'success');
                                            }
                                            setLoading(false);
                                            inProgress = false;
                                            return;
                                        }

                                        if (data.success && data.authorization_url) {
                                            // redirect to payment provider
                                            window.location.href = data.authorization_url;
                                            return;
                                        }

                                        if (data.success) {
                                            if (data.reference) {
                                                showMessage('Payment initiated. Waiting for confirmation...', 'success');
                                                pollTopupStatus(data.reference, amount);
                                            } else {
                                                const summary = await refreshWalletSummary(false);
                                                amountInput.value = '';
                                                if (gatewayHidden) gatewayHidden.value = '';
                                                gatewayButtons.forEach(b => b.classList.remove('ring','ring-2','ring-violet-400'));
                                                showDepositSuccessModal(amount, summary?.topupBalance);
                                            }
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
                                        const res = await fetch('{{ route('vendor.wallet.balance') }}');
                                        const json = await res.json();
                                        if (json.success) {
                                            const wb = document.getElementById('wallet-balance');
                                            if (wb) wb.textContent = 'GHS ' + (json.wallet_balance || 0).toFixed(2);
                                            if (typeof json.vendor_topups_total !== 'undefined') {
                                                const el2 = document.getElementById('topups-balance');
                                                if (el2) el2.textContent = 'GHS ' + (json.vendor_topups_total || 0).toFixed(2);
                                            }
                                            return { topupBalance: json.vendor_topups_total };
                                        }
                                    } catch (e) {}
                                    return null;
                                }

                                // Poll a top-up reference until the gateway/callback marks it completed or failed.
                                async function pollTopupStatus(reference, amount) {
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
                                                    const summary = await refreshWalletSummary(false);
                                                    amountInput.value = '';
                                                    if (gatewayHidden) gatewayHidden.value = '';
                                                    gatewayButtons.forEach(b => b.classList.remove('ring','ring-2','ring-violet-400'));
                                                    showDepositSuccessModal(amount, summary?.topupBalance);
                                                    return;
                                                }

                                                if (j.status === 'failed') {
                                                    showMessage('Top-up failed. Please try again or contact support.', 'error');
                                                    return;
                                                }

                                                if (j.status === 'cancelled') {
                                                    showMessage('Top-up was cancelled. Please try again when ready.', 'error');
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
                                        gatewayButtons.forEach(x => x.classList.remove('ring','ring-2','ring-violet-400'));
                                        b.classList.add('ring','ring-2','ring-violet-400');
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
                                            if (data.flow_type === 'inline') {
                                                feedbackEl.textContent = 'Processing payment, waiting for confirmation...';
                                                InlinePaymentManager.open({ reference: data.reference, authorization_url: data.authorization_url ?? null, gateway_name: data.gateway_name ?? null }, async (status) => {
                                                    if (status === 'paid') {
                                                        feedbackEl.textContent = 'Wallet topped up successfully.';
                                                        await refreshWalletSummary(true);
                                                        closeTopupModal();
                                                    } else if (status === 'failed') {
                                                        feedbackEl.textContent = 'Top-up failed. Please try again.';
                                                    }
                                                    modalConfirm.disabled = false;
                                                });
                                                if (data.reference) {
                                                    pollTopupStatus(data.reference);
                                                }
                                                return;
                                            }

                                            if (data.success && data.authorization_url) {
                                                // redirect
                                                window.location.href = data.authorization_url;
                                                return;
                                            }

                                            if (data.success) {
                                                if (data.reference) {
                                                    feedbackEl.textContent = 'Payment initiated. Waiting for confirmation...';
                                                    pollTopupStatus(data.reference);
                                                } else {
                                                    feedbackEl.textContent = 'Wallet topped up successfully.';
                                                    await refreshWalletSummary(true);
                                                    closeTopupModal();
                                                }
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
                                    class="block w-full pl-10 pr-4 py-3 rounded-lg border border-gray-300 shadow-sm focus:border-violet-500 focus:ring-violet-500"
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
                                class="block w-full px-4 py-3 rounded-lg border border-gray-300 shadow-sm focus:border-violet-500 focus:ring-violet-500"
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
                                    class="block w-full pl-8 pr-4 py-3 rounded-lg border border-gray-300 shadow-sm focus:border-violet-500 focus:ring-violet-500"
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
                            class="block w-full rounded-lg border border-gray-300 shadow-sm focus:border-violet-500 focus:ring-violet-500 px-4 py-3"
                            placeholder="Any additional instructions..."
                        >{{ old('notes') }}</textarea>
                        @error('notes')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between pt-2 border-t border-gray-200">
                        <p class="text-sm text-gray-600">
                            Available balance: <strong class="text-violet-600">GHS {{ number_format($withdrawableBalance, 2) }}</strong>
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

        <div class="bg-white rounded-xl border border-gray-200">
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
                            <tr class="hover:bg-gray-50 transition-colors duration-150 history-row" data-type="{{ $item->history_type ?? (strtolower(str_replace(' ', '-', $item->type)) === 'top-up' || strtolower(str_replace(' ', '-', $item->type)) === 'wallet_topup' ? 'topups' : (strtolower(str_replace(' ', '-', $item->type)) === 'withdrawal' ? 'withdrawals' : 'other')) }}">
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
                                if (val === 'topups' && type === 'topups') r.style.display = '';
                                else if (val === 'withdrawals' && type === 'withdrawals') r.style.display = '';
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
    {{-- Inline payment UI manager (shared) --}}
    @include('components.inline_payment_manager')

    @endsection
