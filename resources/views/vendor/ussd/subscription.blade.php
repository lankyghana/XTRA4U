@extends('layouts.vendor')

@section('title', 'USSD Subscription - XTRA4U')
@section('description', 'Subscribe to the USSD channel and track your session usage')

@section('content')
<x-vendor-layout :vendor="$vendor" title="USSD Subscription"
                 subtitle="Let customers buy from you by dialling a short code" active="ussd">
    <div class="space-y-6">

        @if (session('status'))
            <div class="rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">
                {{ session('status') }}
            </div>
        @endif

        @if (session('error'))
            <div class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">
                {{ session('error') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">
                @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
            </div>
        @endif

        @unless ($ussdEnabled)
            <div class="rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
                The USSD channel is currently disabled platform-wide. Your subscription stays valid, but customers cannot dial in right now.
            </div>
        @endunless

        {{-- Current subscription --}}
        @if ($current)
            @php
                $remaining = $current->remaining_sessions;
                $percentUsed = $current->usage_percentage;
                $daysLeft = $current->remainingDays();
                $exhausted = $remaining <= 0;
                $expiringSoon = $daysLeft <= 7;

                $barColour = $percentUsed >= 95 ? 'bg-red-500'
                    : ($percentUsed >= 80 ? 'bg-amber-500' : 'bg-green-500');
            @endphp

            @if ($exhausted)
                <div class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">
                    <span class="font-semibold">Your sessions are exhausted.</span>
                    Customers can no longer dial your code. Renew or upgrade below to restore access.
                </div>
            @elseif ($current->isExpired())
                <div class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">
                    <span class="font-semibold">Your subscription has expired.</span> Renew below to restore access.
                </div>
            @elseif ($expiringSoon)
                <div class="rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
                    Your subscription expires in {{ $daysLeft }} {{ Str::plural('day', $daysLeft) }}.
                </div>
            @endif

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900">Current Subscription</h2>
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold
                        {{ $current->isUsable() ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                        {{ ucfirst(str_replace('_', ' ', $current->status)) }}
                    </span>
                </div>

                <div class="p-6 grid grid-cols-1 lg:grid-cols-3 gap-6">
                    {{-- Dialled code --}}
                    <div class="lg:col-span-1">
                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Your USSD Code</p>
                        <p class="mt-2 text-2xl font-bold font-mono text-brand-deep-blue break-all">
                            {{ $current->ussd_code ?? '—' }}
                        </p>
                        <p class="mt-1 text-xs text-gray-500">Share this with your customers.</p>

                        <dl class="mt-4 space-y-1 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Plan</dt>
                                <dd class="font-medium text-gray-900">{{ $current->plan?->name ?? '—' }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Expires</dt>
                                <dd class="font-medium text-gray-900">
                                    {{ $current->expires_at?->format('d M Y') ?? '—' }}
                                </dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">Days left</dt>
                                <dd class="font-medium text-gray-900">{{ $daysLeft }}</dd>
                            </div>
                        </dl>
                    </div>

                    {{-- Session usage --}}
                    <div class="lg:col-span-2">
                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Session Usage</p>

                        <div class="mt-3 grid grid-cols-3 gap-4">
                            <div class="rounded-lg border border-gray-200 px-4 py-3">
                                <p class="text-xs text-gray-500">Total</p>
                                <p class="text-xl font-bold text-gray-900">{{ number_format($current->total_sessions) }}</p>
                            </div>
                            <div class="rounded-lg border border-gray-200 px-4 py-3">
                                <p class="text-xs text-gray-500">Used</p>
                                <p class="text-xl font-bold text-gray-900">{{ number_format($current->used_sessions) }}</p>
                            </div>
                            <div class="rounded-lg border border-gray-200 px-4 py-3">
                                <p class="text-xs text-gray-500">Remaining</p>
                                <p class="text-xl font-bold {{ $exhausted ? 'text-red-600' : 'text-green-600' }}">
                                    {{ number_format($remaining) }}
                                </p>
                            </div>
                        </div>

                        <div class="mt-4">
                            <div class="flex justify-between text-xs text-gray-500 mb-1">
                                <span>{{ $percentUsed }}% used</span>
                                @if ($current->carried_over_sessions > 0)
                                    <span>includes {{ number_format($current->carried_over_sessions) }} carried over</span>
                                @endif
                            </div>
                            <div class="w-full h-2.5 bg-gray-200 rounded-full overflow-hidden">
                                <div class="h-2.5 {{ $barColour }} rounded-full" style="width: {{ min(100, $percentUsed) }}%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @else
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 px-6 py-10 text-center">
                <p class="text-sm font-semibold text-gray-900">You have no USSD subscription</p>
                <p class="mt-1 text-sm text-gray-500">
                    Pick a plan below. Once payment clears we issue your unique code
                    (<span class="font-mono">{{ $baseCode ?: '*203*' }}&lt;ext&gt;*{{ $vendor->id }}#</span>) automatically.
                </p>
            </div>
        @endif

        {{-- Available plans --}}
        <div>
            <h2 class="text-lg font-semibold text-gray-900 mb-1">
                {{ $current ? 'Renew or Upgrade' : 'Available Plans' }}
            </h2>
            <p class="text-sm text-gray-500 mb-4">
                @if ($current)
                    Any sessions you have not used are carried over to your new plan.
                @else
                    Compare price and included sessions.
                @endif
            </p>

            {{-- Mobile-money payer details. Used by inline gateways (BulkClix/Moolre)
                 to send the approval prompt; redirect gateways (Paystack) ignore them. --}}
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 mb-4">
                <p class="text-sm font-medium text-gray-900 mb-3">Payment details</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="ussd-payer-phone" class="block text-xs font-medium text-gray-600 mb-1">
                            Mobile Money number
                        </label>
                        <input type="tel" id="ussd-payer-phone" inputmode="numeric"
                               value="{{ $vendor->phone_number }}"
                               placeholder="e.g. 0244123456"
                               class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-deep-blue focus:ring-brand-deep-blue">
                    </div>
                    <div>
                        <label for="ussd-payer-network" class="block text-xs font-medium text-gray-600 mb-1">
                            Network
                        </label>
                        <select id="ussd-payer-network"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-deep-blue focus:ring-brand-deep-blue">
                            <option value="">Auto-detect</option>
                            <option value="MTN">MTN</option>
                            <option value="TELECEL">Telecel</option>
                            <option value="AIRTELTIGO">AirtelTigo</option>
                        </select>
                    </div>
                </div>
                <p class="mt-2 text-xs text-gray-500">
                    You'll get a prompt on this number to approve the payment. Leave the network on
                    <span class="font-medium">Auto-detect</span> unless it's a ported number.
                </p>
                <div id="ussd-purchase-message" class="hidden mt-3 rounded-lg px-4 py-3 text-sm"></div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                @forelse ($plans as $plan)
                    @php
                        $isCurrentPlan = $current && $current->ussd_plan_id === $plan->id;
                        $isUpgrade = $current && $plan->price > $current->price_paid;
                        $label = $isCurrentPlan ? 'Renew' : ($current ? ($isUpgrade ? 'Upgrade' : 'Switch') : 'Subscribe');
                        $perSession = $plan->included_sessions > 0 ? $plan->price / $plan->included_sessions : 0;
                    @endphp

                    <div class="bg-white rounded-xl border {{ $isCurrentPlan ? 'border-brand-deep-blue ring-1 ring-brand-deep-blue' : 'border-gray-200' }} shadow-sm flex flex-col">
                        <div class="p-5 flex-1">
                            <div class="flex items-start justify-between gap-2">
                                <h3 class="text-base font-bold text-gray-900">{{ $plan->name }}</h3>
                                @if ($isCurrentPlan)
                                    <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-blue-100 text-blue-700">Current</span>
                                @endif
                            </div>

                            @if ($plan->description)
                                <p class="mt-1 text-xs text-gray-500">{{ $plan->description }}</p>
                            @endif

                            <p class="mt-4 text-3xl font-bold text-gray-900">
                                <span class="text-base font-medium text-gray-500">GHS</span>
                                {{ number_format($plan->price, 2) }}
                            </p>
                            <p class="text-xs text-gray-500">per {{ $plan->duration_days }} {{ Str::plural('day', $plan->duration_days) }}</p>

                            <ul class="mt-4 space-y-2 text-sm text-gray-700">
                                <li class="flex items-center gap-2">
                                    <svg class="w-4 h-4 text-green-500 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                    </svg>
                                    <span><span class="font-semibold">{{ number_format($plan->included_sessions) }}</span> sessions</span>
                                </li>
                                <li class="flex items-center gap-2">
                                    <svg class="w-4 h-4 text-green-500 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                    </svg>
                                    <span>GHS {{ number_format($perSession, 4) }} per session</span>
                                </li>
                                <li class="flex items-center gap-2 text-gray-500">
                                    <svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                    </svg>
                                    <span class="font-mono text-xs">{{ $baseCode ?: '*203*' }}{{ $plan->extension_code }}*{{ $vendor->id }}#</span>
                                </li>
                            </ul>
                        </div>

                        <div class="px-5 pb-5">
                            <button type="button"
                                    data-ussd-buy
                                    data-plan-id="{{ $plan->id }}"
                                    data-plan-name="{{ $plan->name }}"
                                    class="w-full px-4 py-2.5 rounded-lg text-sm font-semibold transition-colors disabled:opacity-60 disabled:cursor-not-allowed
                                    {{ $isCurrentPlan
                                        ? 'bg-white border border-brand-deep-blue text-brand-deep-blue hover:bg-blue-50'
                                        : 'bg-brand-deep-blue text-white hover:bg-brand-bright-blue' }}">
                                {{ $label }}
                            </button>
                        </div>
                    </div>
                @empty
                    <div class="md:col-span-2 lg:col-span-3 bg-white rounded-xl border border-gray-200 px-6 py-10 text-center">
                        <p class="text-sm font-medium text-gray-900">No plans are available right now</p>
                        <p class="mt-1 text-sm text-gray-500">Please check back shortly.</p>
                    </div>
                @endforelse
            </div>
        </div>

        {{-- History --}}
        @if ($history->isNotEmpty())
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                    <h2 class="text-lg font-semibold text-gray-900">Subscription History</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Plan</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Activated</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Expires</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wide">Paid</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wide">Sessions</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($history as $row)
                                <tr>
                                    <td class="px-6 py-3 font-medium text-gray-900">{{ $row->plan?->name ?? '—' }}</td>
                                    <td class="px-6 py-3 text-gray-600">{{ $row->activated_at?->format('d M Y') ?? '—' }}</td>
                                    <td class="px-6 py-3 text-gray-600">{{ $row->expires_at?->format('d M Y') ?? '—' }}</td>
                                    <td class="px-6 py-3 text-right text-gray-900">GHS {{ number_format($row->price_paid, 2) }}</td>
                                    <td class="px-6 py-3 text-right text-gray-600">
                                        {{ number_format($row->used_sessions) }} / {{ number_format($row->total_sessions) }}
                                    </td>
                                    <td class="px-6 py-3">
                                        <span class="px-2 py-0.5 rounded-full text-xs font-semibold
                                            {{ $row->status === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' }}">
                                            {{ ucfirst(str_replace('_', ' ', $row->status)) }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>

    @include('components.inline_payment_manager')

    <script>
    (function () {
        const purchaseUrl = @json(route('vendor.ussd.subscription.purchase'));
        const statusBase  = @json(url('/vendor/ussd/subscription/status'));
        const reloadUrl   = @json(route('vendor.ussd.subscription.index'));
        const csrfToken   = @json(csrf_token());

        const phoneInput = document.getElementById('ussd-payer-phone');
        const networkInput = document.getElementById('ussd-payer-network');
        const messageBox = document.getElementById('ussd-purchase-message');
        const buttons = document.querySelectorAll('[data-ussd-buy]');

        function showMessage(text, type) {
            if (!messageBox) return;
            const styles = {
                info: 'bg-blue-50 border border-blue-200 text-blue-800',
                error: 'bg-red-50 border border-red-200 text-red-800',
                success: 'bg-green-50 border border-green-200 text-green-800',
            };
            messageBox.className = 'mt-3 rounded-lg px-4 py-3 text-sm ' + (styles[type] || styles.info);
            messageBox.textContent = text;
            messageBox.classList.remove('hidden');
        }

        function clearMessage() {
            if (messageBox) messageBox.classList.add('hidden');
        }

        function setBusy(busy) {
            buttons.forEach(b => { b.disabled = busy; });
        }

        async function buy(button) {
            clearMessage();
            setBusy(true);

            const body = new URLSearchParams();
            body.append('plan_id', button.getAttribute('data-plan-id'));
            if (phoneInput && phoneInput.value.trim() !== '') {
                body.append('payer_phone', phoneInput.value.trim());
            }
            if (networkInput && networkInput.value !== '') {
                body.append('network', networkInput.value);
            }

            let data;
            try {
                const res = await fetch(purchaseUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                    },
                    body: body.toString(),
                });
                data = await res.json();
            } catch (e) {
                setBusy(false);
                showMessage('Network error. Please try again.', 'error');
                return;
            }

            if (!data || !data.success) {
                setBusy(false);
                showMessage((data && data.message) || 'Failed to start the purchase.', 'error');
                return;
            }

            // Redirect gateways (Paystack) send the vendor's browser away to the
            // gateway's own checkout page. Inline MoMo gateways (BulkClix/Moolre)
            // never return an authorization_url — they push an approval prompt
            // to the phone and we poll our status endpoint until it settles.
            if (data.flow_type === 'inline') {
                showMessage('Payment prompt sent. Approve it on your phone to activate your plan.', 'info');

                const pollUrl = statusBase + '/' + encodeURIComponent(data.reference);

                window.InlinePaymentManager.open({
                    reference: data.reference,
                    authorization_url: null,
                    gateway_name: data.gateway_name || null,
                    flow_type: data.flow_type,
                    no_redirect: true,
                    poll_url: pollUrl,
                }, function (status) {
                    if (status === 'paid') {
                        showMessage('Payment confirmed. Activating your subscription…', 'success');
                        window.location.href = reloadUrl;
                        return true; // suppress InlinePaymentManager's own redirect
                    }
                    if (status === 'failed') {
                        setBusy(false);
                        showMessage('The payment was not completed. Please try again.', 'error');
                    } else if (status === 'timeout') {
                        setBusy(false);
                        showMessage('We could not confirm the payment in time. If you approved it, refresh this page in a moment.', 'error');
                    }
                });
            } else if (data.authorization_url) {
                showMessage('Redirecting to secure payment page…', 'info');
                window.location.href = data.authorization_url;
            } else {
                setBusy(false);
                showMessage('Unable to start the payment. Please try again.', 'error');
            }
        }

        buttons.forEach(button => {
            button.addEventListener('click', () => buy(button));
        });
    })();
    </script>
</x-vendor-layout>
@endsection
