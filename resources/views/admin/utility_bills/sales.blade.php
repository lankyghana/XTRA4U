@extends('layouts.admin')

@section('content')
<x-admin-layout title="Utility Bill Sales" subtitle="Every Utility Bill transaction, with recovery tools" active="utility-bill-sales">
    <div class="space-y-6">
        @if (session('success'))<div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg">{{ session('success') }}</div>@endif
        @if (session('error'))<div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg">{{ session('error') }}</div>@endif

        @if ($attentionCount)
            <a href="{{ route('admin.utility-bill-sales.index', ['fulfillment' => 'attention']) }}" class="block bg-amber-50 border border-amber-200 text-amber-900 px-4 py-3 rounded-lg text-sm">
                <strong>{{ $attentionCount }}</strong> paid {{ \Illuminate\Support\Str::plural('bill', $attentionCount) }} need attention (e.g. provider wallet low). The customers have paid.
            </a>
        @endif

        <div class="bg-white shadow-sm rounded-lg p-5">
            <form method="GET" action="{{ route('admin.utility-bill-sales.index') }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 items-end">
                <label class="block text-sm font-medium text-gray-700">Vendor / storefront
                    <select name="vendor_id" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                        <option value="">All</option>
                        <option value="direct" @selected(($filters['vendor_id'] ?? '') === 'direct')>Direct (no vendor)</option>
                        @foreach ($vendors as $v)<option value="{{ $v->id }}" @selected((string) ($filters['vendor_id'] ?? '') === (string) $v->id)>{{ $v->name }}</option>@endforeach
                    </select>
                </label>
                <label class="block text-sm font-medium text-gray-700">Biller
                    <select name="biller" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                        <option value="">All</option>
                        @foreach ($billers as $b)<option value="{{ $b->biller_key }}" @selected(($filters['biller'] ?? '') === $b->biller_key)>{{ $b->biller_label }}</option>@endforeach
                    </select>
                </label>
                <label class="block text-sm font-medium text-gray-700">Payment
                    <select name="payment" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                        <option value="">All</option>
                        @foreach (['paid' => 'Paid', 'unpaid' => 'Unpaid', 'failed' => 'Failed'] as $v => $l)<option value="{{ $v }}" @selected(($filters['payment'] ?? '') === $v)>{{ $l }}</option>@endforeach
                    </select>
                </label>
                <label class="block text-sm font-medium text-gray-700">Fulfillment
                    <select name="fulfillment" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                        <option value="">All</option>
                        @foreach (\App\Services\UtilityBills\FulfillmentStatus::ALL as $s)<option value="{{ $s }}" @selected(($filters['fulfillment'] ?? '') === $s)>{{ \Illuminate\Support\Str::headline($s) }}</option>@endforeach
                    </select>
                </label>
                <label class="block text-sm font-medium text-gray-700">From<input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="mt-1 w-full rounded-md border-gray-300 shadow-sm"></label>
                <label class="block text-sm font-medium text-gray-700">To<input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="mt-1 w-full rounded-md border-gray-300 shadow-sm"></label>
                <label class="block text-sm font-medium text-gray-700 sm:col-span-2">Reference / account
                    <input name="q" value="{{ $filters['q'] ?? '' }}" class="mt-1 w-full rounded-md border-gray-300 shadow-sm" placeholder="UB…, provider ref, account number or name">
                </label>
                <div class="sm:col-span-2 lg:col-span-4 flex gap-2">
                    <button type="submit" class="inline-flex items-center px-4 py-2 bg-brand-violet text-white rounded-lg text-sm font-semibold">Apply Filters</button>
                    <a href="{{ route('admin.utility-bill-sales.index') }}" class="inline-flex items-center px-4 py-2 bg-gray-100 text-gray-700 rounded-md text-sm font-semibold">Reset</a>
                </div>
            </form>
        </div>

        <x-admin.table :headers="['Reference', 'Vendor', 'Biller', 'Account', 'Bill', 'Payment', 'Fulfillment', 'Vendor comm.', 'Provider comm.', 'Provider ref', 'Date']">
            @forelse ($sales as $s)
                <tr>
                    <td><a class="text-brand-violet font-medium hover:underline" href="{{ route('admin.utility-bill-sales.show', $s) }}">{{ $s->public_ref }}</a></td>
                    <td>{{ $s->vendor?->name ?? 'Direct' }}</td>
                    <td>{{ $s->biller_label }}</td>
                    <td>{{ $s->maskedAccount() }}<span class="block text-xs text-gray-500">{{ $s->account_name }}</span></td>
                    <td class="whitespace-nowrap">GHS {{ number_format((float) $s->bill_amount, 2) }}</td>
                    <td><x-admin.status :status="$s->order?->payment_status ?? 'unknown'" /></td>
                    <td><x-admin.status :status="in_array($s->fulfillment_status, ['attention']) ? 'failed' : ($s->fulfillment_status === 'provider_refunded' ? 'refunded' : $s->fulfillment_status)" :label="\App\Services\UtilityBills\FulfillmentStatus::label($s->fulfillment_status)" /></td>
                    <td class="whitespace-nowrap">
                        @if ($s->vendor_id)
                            GHS {{ number_format((float) $s->commission_amount, 2) }}
                            <span class="block text-xs text-gray-500">{{ $s->commission_status }}</span>
                        @else &mdash; @endif
                    </td>
                    <td class="whitespace-nowrap">{{ $s->provider_commission_earned !== null ? 'GHS '.number_format((float) $s->provider_commission_earned, 2) : '—' }}</td>
                    <td class="text-xs">{{ $s->provider_order_reference ?? '—' }}</td>
                    <td class="whitespace-nowrap text-gray-500">{{ $s->created_at?->format('d M Y H:i') }}</td>
                </tr>
            @empty
                <tr><td colspan="11" class="text-center text-sm text-gray-500 py-6">No utility bill sales found.</td></tr>
            @endforelse
        </x-admin.table>

        @if ($sales->hasPages())<div class="flex justify-end">{{ $sales->links() }}</div>@endif
    </div>
</x-admin-layout>
@endsection
