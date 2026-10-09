@extends('layouts.vendor')

@section('title', 'Utility Bill Sales - XTRA4U')

@section('content')
<x-vendor-layout :vendor="$vendor" title="Utility Bill Sales" subtitle="Bills paid through your store, and the commission you earned" active="utility-bills">
    <div class="space-y-6">
        <div class="grid grid-cols-2 lg:grid-cols-5 gap-3">
            @foreach ([
                ['Sales value', 'GHS '.number_format((float) $metrics['sales_value'], 2), 'Completed bills (not your earnings)'],
                ['Successful sales', $metrics['successful'], 'Completed'],
                ['Processing', $metrics['processing'], 'Paid, not yet complete'],
                ['Failed', $metrics['failed'], 'Not completed'],
                ['Commission earned', 'GHS '.number_format((float) $metrics['commission_earned'], 2), 'Credited to your wallet'],
            ] as [$label, $value, $hint])
                <div class="bg-white rounded-xl border border-gray-200 p-4">
                    <p class="text-xs font-medium text-gray-500">{{ $label }}</p>
                    <p class="mt-1 text-xl font-semibold text-gray-900">{{ $value }}</p>
                    <p class="mt-0.5 text-xs text-gray-400">{{ $hint }}</p>
                </div>
            @endforeach
        </div>

        <div class="bg-white rounded-xl border border-gray-200 p-4">
            <form method="GET" action="{{ route('vendor.utility-bills.index') }}" class="grid gap-3 sm:grid-cols-4 items-end">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Status</label>
                    <select name="status" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                        <option value="">All</option>
                        @foreach (['completed' => 'Completed', 'processing' => 'Processing', 'failed' => 'Failed'] as $value => $label)
                            <option value="{{ $value }}" @selected($selectedStatus === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700">Reference</label>
                    <input name="q" value="{{ $searchTerm }}" class="mt-1 w-full rounded-md border-gray-300 shadow-sm" placeholder="e.g. UB1234ABCD">
                </div>
                <div class="flex gap-2">
                    <x-button type="submit" variant="primary">Filter</x-button>
                    <x-button href="{{ route('vendor.utility-bills.index') }}" variant="secondary">Reset</x-button>
                </div>
            </form>
        </div>

        <x-table :headers="['Reference', 'Bill', 'Account', 'Bill amount', 'Status', 'Commission', 'Date']">
            @forelse ($sales as $sale)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 text-sm font-medium">
                        <a class="text-brand-violet hover:underline" href="{{ route('vendor.utility-bills.show', $sale->public_ref) }}">{{ $sale->public_ref }}</a>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-700">{{ $sale->biller_label }}</td>
                    <td class="px-6 py-4 text-sm text-gray-700">{{ $sale->maskedAccount() }}</td>
                    <td class="px-6 py-4 text-sm text-gray-900">GHS {{ number_format((float) $sale->bill_amount, 2) }}</td>
                    <td class="px-6 py-4 text-sm">
                        <x-badge :variant="match ($sale->vendorStatusLabel()) { 'Completed' => 'completed', 'Failed' => 'failed', default => 'processing' }">{{ $sale->vendorStatusLabel() }}</x-badge>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-900">
                        @if ($sale->commission_status === 'credited')
                            GHS {{ number_format((float) $sale->commission_amount, 2) }}
                        @elseif ($sale->commission_status === 'pending' && ! $sale->isTerminal())
                            <span class="text-gray-400">Pending</span>
                        @else
                            <span class="text-gray-400">&mdash;</span>
                        @endif
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ $sale->created_at?->format('Y-m-d H:i') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-6 py-4">
                        <div class="text-center text-sm text-gray-500 sticky left-0 w-screen max-w-[calc(100vw-3rem)]">No utility bill sales yet.</div>
                    </td>
                </tr>
            @endforelse
        </x-table>

        @if ($sales->hasPages())
            <div class="flex justify-end pt-2">{{ $sales->links() }}</div>
        @endif
    </div>
</x-vendor-layout>
@endsection
