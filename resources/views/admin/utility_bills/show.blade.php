@extends('layouts.admin')

@section('content')
@php
    use App\Services\UtilityBills\FulfillmentStatus as FS;
    $paid = in_array($sale->order?->payment_status, ['paid', 'completed'], true);
    $closed = in_array($sale->fulfillment_status, [FS::FAILED, FS::PROVIDER_REFUNDED], true);
    $retryable = $paid && in_array($sale->fulfillment_status, [FS::ATTENTION, FS::QUEUED, FS::SUBMITTING], true) && $sale->provider_order_reference === null;
    $pollable = in_array($sale->fulfillment_status, FS::REFRESHABLE, true);
    $unresolved = $sale->fulfillment_status === FS::PROVIDER_UNRESOLVED;
@endphp
<x-admin-layout title="Utility Bill {{ $sale->public_ref }}" subtitle="{{ $sale->biller_label }} · {{ $sale->vendor?->name ?? 'Direct sale' }}" active="utility-bill-sales">
    <div class="space-y-6 max-w-4xl">
        @if (session('success'))<div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg">{{ session('success') }}</div>@endif
        @if (session('error'))<div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg">{{ session('error') }}</div>@endif
        @error('confirm')<div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg">Please tick the confirmation box.</div>@enderror

        <a href="{{ route('admin.utility-bill-sales.index') }}" class="text-sm text-brand-violet hover:underline">&larr; All utility bill sales</a>

        <div class="grid gap-4 lg:grid-cols-2">
            <dl class="bg-white rounded-xl border border-gray-200 divide-y divide-gray-100 text-sm">
                @foreach ([
                    'Reference' => $sale->public_ref,
                    'Customer payment' => ($sale->order?->payment_status ?? '?').' ('.($sale->order?->payment_integrity_status ?? '?').')',
                    'Fulfillment' => FS::label($sale->fulfillment_status),
                    'Biller' => $sale->biller_label,
                    'Account' => $sale->account_number,
                    'Account name' => $sale->account_name ?? '—',
                    'Customer phone' => $sale->customer_phone ?? '—',
                    'Bill amount' => 'GHS '.number_format((float) $sale->bill_amount, 2),
                    'Customer paid (expected)' => 'GHS '.number_format((float) $sale->expected_amount, 2),
                    'Vendor / storefront' => $sale->vendor?->name ?? 'Direct (no vendor)',
                    'Created' => $sale->created_at?->format('d M Y H:i:s'),
                ] as $label => $value)
                    <div class="flex justify-between gap-4 px-4 py-2.5"><dt class="text-gray-500">{{ $label }}</dt><dd class="text-right font-medium text-gray-900 break-all">{{ $value }}</dd></div>
                @endforeach
            </dl>

            <dl class="bg-white rounded-xl border border-gray-200 divide-y divide-gray-100 text-sm">
                @foreach ([
                    'Our request reference' => ($sale->provider_request_reference ?? '—').' (attempt '.$sale->provider_attempt.')',
                    'Provider order reference' => $sale->provider_order_reference ?? '—',
                    'Provider status' => $sale->provider_status ?? '—',
                    'Provider reason' => $sale->provider_status_reason ?? '—',
                    'Provider commission (to XTRA4U)' => $sale->provider_commission_earned !== null ? 'GHS '.number_format((float) $sale->provider_commission_earned, 2) : ($sale->provider_commission_share_percent !== null ? $sale->provider_commission_share_percent.'% share' : '—'),
                    'Vendor commission terms (frozen)' => $sale->vendor_id ? ($sale->commission_type === 'fixed' ? 'GHS '.number_format((float) $sale->commission_value, 2).' flat' : rtrim(rtrim((string) $sale->commission_value, '0'), '.').'% of '.str_replace('_', ' ', $sale->commission_basis).' (GHS '.number_format((float) $sale->commission_basis_amount, 2).')') : 'n/a (direct sale)',
                    'Vendor commission' => $sale->vendor_id ? 'GHS '.number_format((float) $sale->commission_amount, 2).' — '.$sale->commission_status.($sale->commission_credited_at ? ' '.$sale->commission_credited_at->format('d M Y H:i') : '') : '—',
                    'Wallet ledger id' => $sale->commission_wallet_ledger_id ?? '—',
                    'Last error' => $sale->last_error_code ? $sale->last_error_code.': '.$sale->last_error_message : '—',
                    'Submit attempts' => $sale->submit_attempts,
                    'Status checks' => $sale->status_check_attempts.($sale->last_status_check_at ? ' (last '.$sale->last_status_check_at->diffForHumans().')' : ''),
                    'Next status check' => $sale->next_status_check_at?->diffForHumans() ?? ($unresolved ? 'stopped (unresolved)' : '—'),
                ] as $label => $value)
                    <div class="flex justify-between gap-4 px-4 py-2.5"><dt class="text-gray-500">{{ $label }}</dt><dd class="text-right font-medium text-gray-900 break-all">{{ $value }}</dd></div>
                @endforeach
            </dl>
        </div>

        @if ($sale->incidents->isNotEmpty())
            <p class="text-sm text-gray-600">Affected by:
                @foreach ($sale->incidents as $inc)
                    <a href="{{ route('admin.utility-bill-incidents.show', $inc) }}" class="text-brand-violet hover:underline">{{ $inc->label() }}</a> ({{ $inc->isActive() ? 'active' : 'recovered' }})@if (! $loop->last), @endif
                @endforeach
            </p>
        @endif

        <div class="bg-white rounded-xl border border-gray-200 p-5 space-y-4">
            <h2 class="text-base font-semibold text-gray-900">Recovery</h2>

            @if ($retryable)
                <form method="POST" action="{{ route('admin.utility-bill-sales.retry', $sale) }}" class="rounded-lg border border-gray-200 p-4">@csrf
                    <p class="text-sm font-semibold text-gray-900">Retry the existing attempt <span class="font-normal text-gray-500">&middot; SAME provider reference</span></p>
                    <p class="text-sm text-gray-600 mt-1">Re-sends request reference <code class="text-xs">{{ $sale->provider_request_reference ?? 'XU-…-A'.$sale->provider_attempt }}</code>. If KiNG FLEXY already has this order it simply returns it, so this <strong>cannot</strong> pay the bill twice. Use this for timeouts, a low provider wallet that has been topped up, or a lost job.</p>
                    <button class="mt-3 px-4 py-2 bg-brand-violet text-white rounded-lg text-sm font-semibold">Retry this attempt</button>
                </form>
            @endif

            @if ($unresolved)
                <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    <p class="font-semibold">Provider status unresolved &middot; automatic checks stopped</p>
                    <p class="mt-1">KiNG FLEXY accepted this bill (provider order <code class="text-xs">{{ $sale->provider_order_reference }}</code>) but gave no final status within the automatic polling window. This is <strong>not</strong> a failure: the customer payment, both references and the frozen commission terms are unchanged, and nothing will be re-sent. Refresh the status (it queries the same provider order); if KiNG FLEXY reports it completed, it is finalised and any vendor commission is credited once.</p>
                </div>
            @endif

            @if ($pollable)
                <form method="POST" action="{{ route('admin.utility-bill-sales.refresh', $sale) }}" class="rounded-lg border border-gray-200 p-4">@csrf
                    <p class="text-sm font-semibold text-gray-900">Refresh status from the provider</p>
                    <p class="text-sm text-gray-600 mt-1">Read-only. Asks KiNG FLEXY for the current status of <code class="text-xs">{{ $sale->provider_order_reference }}</code>. Uses the shared status budget.</p>
                    <button class="mt-3 px-4 py-2 bg-gray-100 text-gray-800 rounded-lg text-sm font-semibold">Refresh status</button>
                </form>
            @endif

            @if ($unresolved)
                <form method="POST" action="{{ route('admin.utility-bill-sales.resume-polling', $sale) }}" class="rounded-lg border border-gray-200 p-4">@csrf
                    <p class="text-sm font-semibold text-gray-900">Resume automatic status checks</p>
                    <p class="text-sm text-gray-600 mt-1">Starts one new bounded polling window ({{ (int) config('utility_bills.status_poll_max_checks') }} checks / {{ (int) config('utility_bills.status_poll_max_hours') }} h) for the same provider order. Use it when KiNG FLEXY says the bill is still being worked on.</p>
                    <button class="mt-3 px-4 py-2 bg-gray-100 text-gray-800 rounded-lg text-sm font-semibold">Resume automatic checks</button>
                </form>
            @endif

            @if ($paid && $sale->fulfillment_status === FS::PROVIDER_REFUNDED)
                <form method="POST" action="{{ route('admin.utility-bill-sales.new-attempt', $sale) }}" class="rounded-lg border-2 border-amber-300 bg-amber-50 p-4">@csrf
                    <p class="text-sm font-semibold text-amber-900">Start a NEW provider attempt <span class="font-normal">&middot; NEW provider reference &middot; can pay the bill a second time</span></p>
                    <p class="text-sm text-amber-900 mt-1">KiNG FLEXY refunded attempt {{ $sale->provider_attempt }} to <em>our provider wallet</em> (this is <strong>not</strong> a customer refund; the customer's payment stays paid). Starting attempt {{ $sale->provider_attempt + 1 }} creates a new request reference and debits the provider wallet again if it succeeds. XTRA4U first asks the provider live to confirm attempt {{ $sale->provider_attempt }} is still <code>refunded</code>; if it cannot confirm, nothing is created. Attempt {{ $sale->provider_attempt }} stays on record below. Only available after a provider-confirmed refund.</p>
                    <label class="block text-sm font-medium text-amber-900 mt-3">Reason (recorded in the audit trail)
                        <input type="text" name="reason" required minlength="5" maxlength="255" class="mt-1 w-full rounded-md border-amber-300 shadow-sm" placeholder="e.g. Provider refunded; customer still needs this bill">
                    </label>
                    @error('reason')<p class="text-xs text-red-700 mt-1">{{ $message }}</p>@enderror
                    <label class="flex items-start gap-2 text-sm text-amber-900 mt-3"><input type="checkbox" name="confirm" value="1" class="mt-1"> I understand this creates a NEW provider payment attempt.</label>
                    <button class="mt-3 px-4 py-2 bg-amber-600 text-white rounded-lg text-sm font-semibold">Start new provider attempt</button>
                </form>
            @endif

            @if ($paid && $sale->fulfillment_status === FS::FAILED)
                <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900">
                    <p class="font-semibold">Provider status: failed</p>
                    <p class="mt-1">KiNG FLEXY's documentation does not define <code>failed</code> as final-and-refunded for utility bills (only <code>refunded</code> is documented as a definitive failure). A new provider attempt is therefore <strong>not permitted</strong> from this state. Confirm the outcome and the wallet with KiNG FLEXY support first.</p>
                </div>
            @endif

            @if (! $retryable && ! $pollable && ! ($paid && in_array($sale->fulfillment_status, [FS::PROVIDER_REFUNDED, FS::FAILED], true)))
                <p class="text-sm text-gray-500">No recovery action applies in the current state.</p>
            @endif
        </div>

        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h2 class="text-base font-semibold text-gray-900 mb-3">Provider attempts</h2>
            <ol class="space-y-3 text-sm">
                @foreach ($closedAttempts as $e)
                    @php $m = $e->meta ?? []; @endphp
                    <li class="rounded-lg border border-gray-200 p-3">
                        <p class="font-medium text-gray-900">Attempt {{ $m['attempt'] ?? '?' }} <span class="text-gray-500 font-normal">&middot; closed {{ $e->created_at?->format('d M Y H:i') }}</span></p>
                        <p class="text-gray-600 break-all">Request reference: {{ $m['request_reference'] ?? '—' }}<br>Provider order reference: {{ $m['provider_order_reference'] ?? '—' }}<br>Provider status: {{ $m['provider_status'] ?? '—' }} {{ ! empty($m['provider_status_reason']) ? '('.$m['provider_status_reason'].')' : '' }}<br>Submitted: {{ $m['submitted_at'] ?? '—' }}<br>New attempt {{ $m['new_attempt'] ?? '?' }} started by {{ $m['started_by'] ?? '—' }}: {{ $m['reason'] ?? '—' }}</p>
                    </li>
                @endforeach
                <li class="rounded-lg border border-brand-violet/30 bg-brand-violet-soft p-3">
                    <p class="font-medium text-gray-900">Attempt {{ $sale->provider_attempt }} <span class="text-gray-500 font-normal">&middot; current</span></p>
                    <p class="text-gray-600 break-all">Request reference: {{ $sale->provider_request_reference ?? '—' }}<br>Provider order reference: {{ $sale->provider_order_reference ?? '—' }}<br>Provider status: {{ $sale->provider_status ?? '—' }}</p>
                </li>
            </ol>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h2 class="text-base font-semibold text-gray-900 mb-3">Timeline</h2>
            <ol class="space-y-2 text-sm">
                @foreach ($sale->events as $e)
                    <li class="flex flex-wrap gap-x-3"><span class="text-gray-500 w-40">{{ $e->created_at?->format('d M H:i:s') }}</span>
                        <span class="font-medium">{{ $e->kind }}</span>
                        <span class="text-gray-600">{{ $e->from_status ? $e->from_status.' → ' : '' }}{{ $e->to_status }} {{ $e->http_status ? '(HTTP '.$e->http_status.')' : '' }} {{ $e->detail }}</span>
                        <span class="text-gray-400">{{ $e->actor }}</span></li>
                @endforeach
            </ol>
        </div>
    </div>
</x-admin-layout>
@endsection
