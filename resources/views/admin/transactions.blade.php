@extends('layouts.admin')

@section('title', 'Transactions - Admin Portal')

@section('content')
<x-admin-layout title="Transactions" subtitle="Audit all processed payments and commissions" active="transactions">
    <div class="space-y-4">
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:max-w-3xl">
            <x-admin.stat label="Total" value="{{ $totals['processed'] }}" tone="neutral" />
            <x-admin.stat label="Today" value="{{ $totals['today'] }}" tone="brand" />
            <x-admin.stat label="Revenue" value="GHS {{ number_format($totals['revenue'], 2) }}" tone="success" />
        </div>

        <x-admin.filters :action="route('admin.transactions.index')" :search="$search ?? request('q', '')" placeholder="Search gateway ref, order id, transaction id, vendor, phone" />

        <x-admin.table :headers="['Reference', 'Type', 'Gateway Ref', 'Gateway Tx ID', 'Vendor', 'Amount', 'Commission', 'Status', 'Action', 'Created']">
            @forelse ($transactions as $transaction)
                <tr>
                    <td class="px-6 py-4 text-sm text-gray-900">
                        #{{ $transaction->id }}<br>
                        @if ($transaction->payment_type === 'result_checker')
                            <span class="text-xs text-gray-500">RC Order #{{ $transaction->transactionable_id }}</span>
                        @else
                            <span class="text-xs text-gray-500">Order #{{ $transaction->order_id }}</span>
                        @endif
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-900">
                        @if ($transaction->payment_type === 'result_checker')
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-brand-violet-soft text-brand-violet-deep">RC Order</span>
                        @elseif ($transaction->payment_type === 'afa_registration')
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800">AFA Reg</span>
                        @else
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">Order</span>
                        @endif
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-900">
                        @if ($transaction->payment_type === 'result_checker')
                            <span class="text-xs text-gray-500">{{ $transaction->gateway_transaction_id ?? '—' }}</span>
                        @else
                            <span class="text-xs text-gray-500">{{ $transaction->order?->payment_reference ?? '—' }}</span>
                        @endif
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-900">
                        <span class="text-xs text-gray-500">{{ $transaction->gateway_transaction_id ?? '—' }}</span>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-900">
                        {{ $transaction->vendor?->name ?? 'Unknown Vendor' }}
                    </td>
                    <td class="px-6 py-4 text-sm font-semibold text-gray-900">GHS {{ number_format($transaction->amount, 2) }}</td>
                    <td class="px-6 py-4 text-sm text-gray-900">GHS {{ number_format($transaction->commission_amount, 2) }}</td>
                    <td class="px-6 py-4 text-sm text-gray-900">
                        <x-admin.status :status="$transaction->payment_status ?? ''" />
                    </td>
                    <td class="px-6 py-4 text-sm">
                        @if ($transaction->payment_type === 'result_checker')
                            @php($rcOrder = $transaction->resultCheckerOrder)
                            @if ($rcOrder && !$rcOrder->paid_at)
                                <form method="POST" action="{{ route('admin.transactions.confirm-payment', $transaction) }}" class="inline-block" onsubmit="return confirm('Manually confirm this payment? Only do this after verifying it with the gateway.');">
                                    @csrf
                                    <x-button type="submit" variant="primary" size="sm">Confirm payment</x-button>
                                </form>
                            @elseif ($rcOrder)
                                <x-admin.status status="paid" />
                            @else
                                <span class="text-xs text-gray-500">N/A</span>
                            @endif
                        @else
                            @php($order = $transaction->order)
                            @if ($order && !in_array($order->payment_status, ['paid', 'completed'], true))
                                <form method="POST" action="{{ route('admin.transactions.confirm-payment', $transaction) }}" class="inline-block" onsubmit="return confirm('Manually confirm this payment? Only do this after verifying it with the gateway.');">
                                    @csrf
                                    <x-button type="submit" variant="primary" size="sm">Confirm payment</x-button>
                                </form>
                            @elseif ($order)
                                <x-admin.status status="paid" />
                            @else
                                <span class="text-xs text-gray-500">N/A</span>
                            @endif
                        @endif
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-500">
                        {{ $transaction->created_at?->format('M d, Y H:i') }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="10"><x-admin.empty title="No transactions found" description="Processed payments and commissions will appear here." /></td>
                </tr>
            @endforelse
        </x-admin.table>

        <div class="flex justify-end">
            {{ $transactions->links() }}
        </div>
    </div>
</x-admin-layout>
@endsection
