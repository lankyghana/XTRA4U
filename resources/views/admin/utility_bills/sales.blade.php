@extends('layouts.admin')

@section('content')
<x-admin-layout title="Utility Bill Sales" subtitle="Every Utility Bill transaction, with recovery tools" active="utility-bill-sales">
    <div class="space-y-6">
        @if (session('success'))<div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg">{{ session('success') }}</div>@endif
        @if (session('error'))<div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg">{{ session('error') }}</div>@endif

        @php $p = $pipeline; @endphp
        <section aria-labelledby="ub-pipeline" class="space-y-3">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="ub-pipeline" class="text-sm font-semibold text-gray-900">Provider pipeline</h2>
                <p class="text-xs text-gray-500">
                    Pay capacity {{ $p['pay_per_minute'] }}/min (&asymp;{{ number_format($p['pay_per_hour']) }}/hour), the configured KiNG FLEXY budget @if ($p['provider_documented_pay_per_minute'])(provider documents {{ $p['provider_documented_pay_per_minute'] }}/min per key)@endif. Used this minute: {{ $p['pay_used_this_minute'] }}/{{ $p['pay_per_minute'] }}.
                </p>
            </div>
            <div class="grid gap-3 grid-cols-2 lg:grid-cols-5">
                <x-admin.stat label="Awaiting submission" :value="number_format($p['awaiting_submission'])"
                    :hint="$p['awaiting_submission'] ? 'Oldest paid '.$p['oldest_waiting_minutes'].' min ago'.($p['retry_scheduled'] ? ' · '.$p['retry_scheduled'].' retry scheduled' : '') : 'None waiting'"
                    :tone="$p['accumulating'] ? 'warning' : 'neutral'" :href="route('admin.utility-bill-sales.index', ['fulfillment' => 'queued'])" />
                <x-admin.stat label="Submitting now" :value="number_format($p['submitting'])" :href="route('admin.utility-bill-sales.index', ['fulfillment' => 'submitting'])" />
                <x-admin.stat label="With provider" :value="number_format($p['with_provider'])" hint="Pending or processing at KiNG FLEXY" tone="brand" :href="route('admin.utility-bill-sales.index', ['fulfillment' => 'provider_pending'])" />
                <x-admin.stat label="Needs attention" :value="number_format($p['attention'])" :tone="$p['attention'] ? 'warning' : 'neutral'" :href="route('admin.utility-bill-sales.index', ['fulfillment' => 'attention'])" />
                <x-admin.stat label="Status unresolved" :value="number_format($p['unresolved'])" hint="Automatic checks stopped; not failed" :tone="$p['unresolved'] ? 'warning' : 'neutral'" :href="route('admin.utility-bill-sales.index', ['fulfillment' => 'provider_unresolved'])" />
            </div>
            @if ($p['state'] === 'outage')
                <p class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
                    <strong>Provider outage.</strong> KiNG FLEXY is not processing normally ({{ $p['incidents']->map->label()->implode('; ') }}). Paid orders wait or retry with their existing references; nothing is failed or refunded automatically.
                    @if ($p['awaiting_submission']) {{ number_format($p['awaiting_submission']) }} {{ \Illuminate\Support\Str::plural('bill', $p['awaiting_submission']) }} waiting for submission. @endif
                </p>
            @elseif ($p['state'] === 'capacity_backlog')
                <p class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    <strong>Provider capacity backlog.</strong> {{ number_format($p['awaiting_submission']) }} paid {{ \Illuminate\Support\Str::plural('bill', $p['awaiting_submission']) }} are queued faster than KiNG FLEXY's pay limit accepts them
                    (paid in the last hour: {{ $p['paid_last_hour'] }}, submitted: {{ $p['submitted_last_hour'] }}). At {{ $p['pay_per_minute'] }}/min the queue clears in about {{ $p['drain_minutes'] }} min, oldest first. This is a capacity limit, not an outage; the provider limit can only be raised by KiNG FLEXY.
                </p>
            @elseif ($p['state'] === 'flowing')
                <p class="text-xs text-gray-500">Queue is draining normally: about {{ $p['drain_minutes'] }} min at the current pay capacity, oldest first.</p>
            @endif

            @if ($p['incidents']->isNotEmpty())
                <div class="space-y-2">
                    <div class="flex items-baseline justify-between gap-2">
                        <h3 class="text-sm font-semibold text-gray-900">Active incidents</h3>
                        <a href="{{ route('admin.utility-bill-incidents.index') }}" class="text-xs text-brand-violet hover:underline">All incidents</a>
                    </div>
                    @include('admin.utility_bills.partials.incident-rows', ['incidents' => $p['incidents'], 'empty' => ''])
                </div>
            @else
                <p class="text-xs text-gray-500"><a href="{{ route('admin.utility-bill-incidents.index') }}" class="text-brand-violet hover:underline">Incident history</a> &middot; no active provider incidents.</p>
            @endif
        </section>

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
                    <td>
                        <x-admin.status :status="match ($s->fulfillment_status) { 'attention', 'provider_unresolved' => 'review', 'provider_refunded' => 'refunded', 'provider_pending', 'provider_processing', 'submitting' => 'processing', default => $s->fulfillment_status }" :label="\App\Services\UtilityBills\FulfillmentStatus::label($s->fulfillment_status)" />
                        @if ($s->fulfillment_status === 'attention' && $s->last_error_code)<span class="block text-xs text-gray-500">{{ \App\Services\UtilityBills\UtilityBillFulfillmentService::reasonLabel($s->last_error_code) }}</span>@endif
                    </td>
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
