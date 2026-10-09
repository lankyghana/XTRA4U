@extends('layouts.admin')

@section('content')
@php
    $oldest = $incident->orders()->orderBy('utility_bill_orders.id')->first(['utility_bill_orders.id', 'public_ref', 'utility_bill_orders.created_at']);
@endphp
<x-admin-layout title="{{ $incident->label() }}" subtitle="KiNG FLEXY incident #{{ $incident->id }}" active="utility-bill-sales">
    <div class="space-y-6 max-w-5xl">
        <a href="{{ route('admin.utility-bill-incidents.index') }}" class="text-sm text-brand-violet hover:underline">&larr; All incidents</a>

        <dl class="grid gap-px overflow-hidden rounded-xl border border-gray-200 bg-gray-100 text-sm sm:grid-cols-2 lg:grid-cols-3">
            @foreach ([
                'State' => $incident->isActive() ? 'Active' : 'Recovered'.($incident->recovered_at ? ' '.$incident->recovered_at->format('d M Y H:i') : ''),
                'Provider' => 'KiNG FLEXY',
                'Issue' => $incident->label(),
                'First detected' => $incident->first_detected_at?->format('d M Y H:i:s'),
                'Last detected' => $incident->last_detected_at?->format('d M Y H:i:s'),
                'Affected orders' => number_format($incident->affected_orders),
                'Occurrences' => number_format($incident->occurrences),
                'Oldest affected order' => $oldest?->public_ref ?? '—',
                'Alerts sent' => $incident->alerts_sent,
                'Last detail' => $incident->last_detail ?? '—',
                'Recovery' => $incident->recovery_note ?? '—',
            ] as $label => $value)
                <div class="bg-white px-4 py-3"><dt class="text-xs uppercase tracking-wide text-gray-500">{{ $label }}</dt><dd class="mt-1 font-medium text-gray-900 break-words">{{ $value }}</dd></div>
            @endforeach
        </dl>

        <p class="text-sm text-gray-600">Each order keeps its own status and full history; open it to refresh or recover it. Orders parked by a provider-wide problem are re-queued automatically (same provider reference) when the provider recovers.</p>

        <x-admin.table :headers="['Reference', 'Vendor', 'Biller', 'Bill', 'Payment', 'Fulfillment', 'Joined incident']">
            @forelse ($orders as $s)
                <tr>
                    <td><a class="text-brand-violet font-medium hover:underline" href="{{ route('admin.utility-bill-sales.show', $s) }}">{{ $s->public_ref }}</a></td>
                    <td>{{ $s->vendor?->name ?? 'Direct' }}</td>
                    <td>{{ $s->biller_label }}</td>
                    <td class="whitespace-nowrap">GHS {{ number_format((float) $s->bill_amount, 2) }}</td>
                    <td><x-admin.status :status="$s->order?->payment_status ?? 'unknown'" /></td>
                    <td>{{ \App\Services\UtilityBills\FulfillmentStatus::label($s->fulfillment_status) }}@if ($s->fulfillment_status === 'attention' && $s->last_error_code)<span class="block text-xs text-gray-500">{{ \App\Services\UtilityBills\UtilityBillFulfillmentService::reasonLabel($s->last_error_code) }}</span>@endif</td>
                    <td class="whitespace-nowrap text-gray-500">{{ \Illuminate\Support\Carbon::parse($s->pivot->first_seen_at)->format('d M H:i') }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-sm text-gray-500 py-6">No orders are attached to this incident.</td></tr>
            @endforelse
        </x-admin.table>

        @if ($orders->hasPages())<div class="flex justify-end">{{ $orders->links() }}</div>@endif
    </div>
</x-admin-layout>
@endsection
