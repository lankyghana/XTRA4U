@extends('layouts.vendor')

@section('title', 'Utility Bill '.$sale->public_ref.' - XTRA4U')

@section('content')
<x-vendor-layout :vendor="$vendor" title="Utility Bill {{ $sale->public_ref }}" subtitle="{{ $sale->biller_label }}" active="utility-bills">
    <div class="max-w-xl space-y-4">
        <a href="{{ route('vendor.utility-bills.index') }}" class="text-sm text-brand-violet hover:underline">&larr; All utility bill sales</a>

        <dl class="bg-white rounded-xl border border-gray-200 divide-y divide-gray-100 text-sm">
            @foreach ([
                'Reference' => $sale->public_ref,
                'Bill' => $sale->biller_label,
                'Account' => $sale->maskedAccount(),
                'Bill amount' => 'GHS '.number_format((float) $sale->bill_amount, 2),
                'Status' => $sale->vendorStatusLabel(),
                'Your commission' => $sale->commission_status === 'credited'
                    ? 'GHS '.number_format((float) $sale->commission_amount, 2).' (credited '.$sale->commission_credited_at?->format('Y-m-d H:i').')'
                    : ($sale->isTerminal() ? 'None' : 'Pending completion'),
                'Date' => $sale->created_at?->format('Y-m-d H:i'),
            ] as $label => $value)
                <div class="flex justify-between gap-4 px-4 py-3">
                    <dt class="text-gray-500">{{ $label }}</dt>
                    <dd class="text-right font-medium text-gray-900">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
        <p class="text-xs text-gray-400">Commission is earned only when the bill is completed.</p>
    </div>
</x-vendor-layout>
@endsection
