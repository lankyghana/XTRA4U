@extends('layouts.admin')

@section('title', 'Transactions - Admin Portal')

@section('content')
<x-admin-layout title="Transactions" subtitle="Audit all processed payments and commissions" active="transactions">
    <div class="space-y-6">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="bg-white border border-gray-200 rounded-xl px-4 py-3">
                <p class="text-xs uppercase text-gray-500">Total</p>
                <p class="text-lg font-semibold text-gray-900">{{ $totals['processed'] }}</p>
            </div>
            <div class="bg-white border border-gray-200 rounded-xl px-4 py-3">
                <p class="text-xs uppercase text-gray-500">Today</p>
                <p class="text-lg font-semibold text-gray-900">{{ $totals['today'] }}</p>
            </div>
            <div class="bg-white border border-gray-200 rounded-xl px-4 py-3">
                <p class="text-xs uppercase text-gray-500">Revenue</p>
                <p class="text-lg font-semibold text-gray-900">GHS {{ number_format($totals['revenue'], 2) }}</p>
            </div>
        </div>

        <x-table :headers="['Reference', 'Vendor', 'Amount', 'Commission', 'Status', 'Created']">
            @forelse ($transactions as $transaction)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 text-sm text-gray-900">
                        #{{ $transaction->id }}<br>
                        <span class="text-xs text-gray-500">Order #{{ $transaction->order_id }}</span>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-900">
                        {{ $transaction->vendor?->name ?? 'Unknown Vendor' }}
                    </td>
                    <td class="px-6 py-4 text-sm font-semibold text-gray-900">GHS {{ number_format($transaction->amount, 2) }}</td>
                    <td class="px-6 py-4 text-sm text-gray-900">GHS {{ number_format($transaction->commission_amount, 2) }}</td>
                    <td class="px-6 py-4 text-sm">
                        <x-badge :variant="$transaction->payment_status === 'completed' ? 'completed' : 'pending'">
                            {{ ucfirst($transaction->payment_status) }}
                        </x-badge>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-500">
                        {{ $transaction->created_at?->format('M d, Y H:i') }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500">No transactions recorded yet.</td>
                </tr>
            @endforelse
        </x-table>

        <div class="flex justify-end">
            {{ $transactions->links() }}
        </div>
    </div>
</x-admin-layout>
@endsection
