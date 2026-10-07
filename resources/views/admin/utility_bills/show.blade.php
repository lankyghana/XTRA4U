@extends('layouts.admin')

@section('content')
@php
    use App\Services\UtilityBills\FulfillmentStatus as FS;
    $paid = in_array($sale->order?->payment_status, ['paid', 'completed'], true);
    $closed = in_array($sale->fulfillment_status, [FS::FAILED, FS::PROVIDER_REFUNDED], true);
    $retryable = $paid && in_array($sale->fulfillment_status, [FS::ATTENTION, FS::QUEUED, FS::SUBMITTING], true) && $sale->provider_order_reference === null;
    $pollable = in_array($sale->fulfillment_status, FS::POLLABLE, true);
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
                    'Next status check' => $sale->next_status_check_at?->diffForHumans() ?? '—',
                ] as $label => $value)
                    <div class="flex justify-between gap-4 px-4 py-2.5"><dt class="text-gray-500">{{ $label }}</dt><dd class="text-right font-medium text-gray-900 break-all">{{ $value }}</dd></div>
                @endforeach
            </dl>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h2 class="text-base font-semibold text-gray-900 mb-3">Recovery</h2>
            <div class="flex flex-wrap gap-3 items-start">
                @if ($retryable)
                    <form method="POST" action="{{ route('admin.utility-bill-sales.retry', $sale) }}">@csrf
                        <button class="px-4 py-2 bg-brand-violet text-white rounded-lg text-sm font-semibold">Retry submission (same provider reference)</button>
                    </form>
                @endif
                @if ($pollable)
                    <form method="POST" action="{{ route('admin.utility-bill-sales.refresh', $sale) }}">@csrf
                        <button class="px-4 py-2 bg-gray-100 text-gray-800 rounded-lg text-sm font-semibold">Refresh status from provider</button>
                    </form>
                @endif
                @if ($paid && $closed)
                    <form method="POST" action="{{ route('admin.utility-bill-sales.new-attempt', $sale) }}" class="border border-amber-200 bg-amber-50 rounded-lg p-3 max-w-md">@csrf
                        <p class="text-sm text-amber-900 mb-2">The provider closed attempt {{ $sale->provider_attempt }} ({{ $sale->provider_status }}). Submitting a <strong>new</strong> attempt pays the provider again from your provider wallet. Only do this once you are sure the previous attempt did not deliver the bill.</p>
                        <label class="flex items-start gap-2 text-sm text-amber-900 mb-2"><input type="checkbox" name="confirm" value="1" class="mt-1"> I confirm a new provider attempt is intended.</label>
                        <button class="px-4 py-2 bg-amber-600 text-white rounded-lg text-sm font-semibold">Submit new attempt</button>
                    </form>
                @endif
                @if (! $retryable && ! $pollable && ! ($paid && $closed))
                    <p class="text-sm text-gray-500">No recovery action applies in the current state.</p>
                @endif
            </div>
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
