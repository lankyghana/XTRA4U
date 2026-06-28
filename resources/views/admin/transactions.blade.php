@extends('layouts.admin')

@section('title', 'Transactions - Admin Portal')

@section('content')
<x-admin-layout title="Transactions" subtitle="Audit all processed payments and commissions" active="transactions">
    <div class="space-y-6">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="bg-gradient-to-br from-brand-deep-blue to-brand-bright-blue rounded-xl px-5 py-4 shadow-lg transform hover:scale-105 transition-transform duration-200">
                <p class="text-xs uppercase text-blue-100 font-medium">Total</p>
                <p class="text-2xl font-bold text-white mt-1">{{ $totals['processed'] }}</p>
            </div>
            <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl px-5 py-4 shadow-lg transform hover:scale-105 transition-transform duration-200">
                <p class="text-xs uppercase text-purple-100 font-medium">Today</p>
                <p class="text-2xl font-bold text-white mt-1">{{ $totals['today'] }}</p>
            </div>
            <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-xl px-5 py-4 shadow-lg transform hover:scale-105 transition-transform duration-200">
                <p class="text-xs uppercase text-green-100 font-medium">Revenue</p>
                <p class="text-2xl font-bold text-white mt-1">GHS {{ number_format($totals['revenue'], 2) }}</p>
            </div>
        </div>

        <form method="GET" action="{{ route('admin.transactions.index') }}" class="flex flex-col sm:flex-row gap-3 sm:items-center">
            <div class="flex-1">
                <input
                    type="text"
                    name="q"
                    value="{{ $search ?? request('q') }}"
                    placeholder="Search by gateway ref, order id, transaction id, vendor, phone"
                    class="w-full text-sm border-gray-300 rounded-lg focus:ring-brand-bright-blue focus:border-brand-bright-blue px-3 py-2 font-medium shadow-sm"
                />
            </div>
            <div class="flex gap-2">
                <button type="submit" class="text-sm px-4 py-2 rounded-lg bg-brand-bright-blue text-white font-medium shadow-sm hover:bg-brand-deep-blue">
                    Search
                </button>
                @if (($search ?? request('q')))
                    <a href="{{ route('admin.transactions.index') }}" class="text-sm px-4 py-2 rounded-lg border border-gray-300 text-gray-700 font-medium shadow-sm hover:bg-gray-50">
                        Clear
                    </a>
                @endif
            </div>
        </form>

        <x-table :headers="['Reference', 'Type', 'Gateway Ref', 'Gateway Tx ID', 'Vendor', 'Amount', 'Commission', 'Status', 'Action', 'Created']">
            @forelse ($transactions as $transaction)
                <tr class="hover:bg-gray-50">
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
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800">RC Order</span>
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
                        {{ ucfirst($transaction->payment_status ?? 'n/a') }}
                    </td>
                    <td class="px-6 py-4 text-sm">
                        @if ($transaction->payment_type === 'result_checker')
                            @php($rcOrder = $transaction->resultCheckerOrder)
                            @if ($rcOrder && !$rcOrder->paid_at)
                                <form method="POST" action="{{ route('admin.transactions.confirm-payment', $transaction) }}" class="inline-block">
                                    @csrf
                                    <button type="submit"
                                            class="text-sm px-3 py-2 rounded-lg bg-purple-600 text-white font-medium shadow-sm hover:bg-purple-700">
                                        Confirm
                                    </button>
                                </form>
                            @elseif ($rcOrder)
                                <span class="text-xs font-medium text-green-700">Paid</span>
                            @else
                                <span class="text-xs text-gray-500">N/A</span>
                            @endif
                        @else
                            @php($order = $transaction->order)
                            @if ($order && !in_array($order->payment_status, ['paid', 'completed'], true))
                                <form method="POST" action="{{ route('admin.transactions.confirm-payment', $transaction) }}" class="inline-block">
                                    @csrf
                                    <button type="submit"
                                            class="text-sm px-3 py-2 rounded-lg bg-brand-bright-blue text-white font-medium shadow-sm hover:bg-brand-deep-blue">
                                        Confirm
                                    </button>
                                </form>
                            @elseif ($order)
                                <span class="text-xs font-medium text-green-700">Paid</span>
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
                    <td colspan="9" class="px-6 py-4 text-center text-sm text-gray-500">No transactions recorded yet.</td>
                </tr>
            @endforelse
        </x-table>

        <div class="flex justify-end">
            {{ $transactions->links() }}
        </div>
    </div>
</x-admin-layout>
@endsection
