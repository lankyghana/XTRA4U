@extends('layouts.admin')

@section('title', 'Vendor Withdrawals - Admin Portal')

@php
    $payoutGateway = \App\Models\PaymentGatewayConfig::getDefault(\App\Models\PaymentGatewayConfig::TYPE_PAYOUT);
@endphp

@section('content')
<x-admin-layout title="Vendor Withdrawals" subtitle="Review and action payout requests submitted by approved vendors" active="withdrawals">
    <div class="space-y-6">
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <div class="bg-gradient-to-br from-yellow-500 to-yellow-600 rounded-xl px-5 py-4 shadow-lg transform hover:scale-105 transition-transform duration-200">
                <p class="text-xs uppercase text-yellow-100 font-medium">Pending</p>
                <p class="text-2xl font-bold text-white mt-1">{{ $summary['pending'] }}</p>
                <p class="text-xs text-yellow-100 mt-1">GHS {{ number_format($summary['pending_amount'], 2) }}</p>
            </div>
            <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl px-5 py-4 shadow-lg transform hover:scale-105 transition-transform duration-200">
                <p class="text-xs uppercase text-blue-100 font-medium">Processing</p>
                <p class="text-2xl font-bold text-white mt-1">{{ $summary['processing'] }}</p>
            </div>
            <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-xl px-5 py-4 shadow-lg transform hover:scale-105 transition-transform duration-200">
                <p class="text-xs uppercase text-green-100 font-medium">Approved</p>
                <p class="text-2xl font-bold text-white mt-1">{{ $summary['approved'] }}</p>
            </div>
            <div class="bg-gradient-to-br from-red-500 to-red-600 rounded-xl px-5 py-4 shadow-lg transform hover:scale-105 transition-transform duration-200">
                <p class="text-xs uppercase text-red-100 font-medium">Rejected</p>
                <p class="text-2xl font-bold text-white mt-1">{{ $summary['rejected'] }}</p>
            </div>
        </div>

        @if (session('status'))
            <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg">
                {{ session('status') }}
            </div>
        @endif

        @error('withdrawal')
            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
                {{ $message }}
            </div>
        @enderror

        <div class="flex flex-wrap gap-2">
            @php
                $filters = [
                    '' => 'All',
                    \App\Models\VendorWithdrawal::STATUS_PENDING => 'Pending',
                    \App\Models\VendorWithdrawal::STATUS_PROCESSING => 'Processing',
                    \App\Models\VendorWithdrawal::STATUS_APPROVED => 'Approved',
                    \App\Models\VendorWithdrawal::STATUS_REJECTED => 'Rejected',
                ];
            @endphp
            @foreach ($filters as $value => $label)
                <a href="{{ route('admin.withdrawals.index', array_filter(['status' => $value])) }}"
                   class="px-3 py-2 rounded-lg text-sm font-medium {{ ($statusFilter ?? '') === $value ? 'bg-brand-deep-blue text-white' : 'text-gray-600 border border-gray-200' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>

        <x-table :headers="['Vendor', 'MoMo Details', 'Amount', 'Status', 'Reference']">
            @forelse ($withdrawals as $withdrawal)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium text-gray-900">{{ $withdrawal->vendor->name }}</div>
                        <div class="text-sm text-gray-500">{{ $withdrawal->vendor->email }}</div>
                        <div class="text-xs text-gray-400 mt-1">{{ $withdrawal->created_at?->format('M d, Y • H:i') }}</div>
                    </td>
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-2">
                            @if($withdrawal->momo_network === 'MTN')
                                <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-yellow-400 text-yellow-900 text-xs font-bold">MTN</span>
                            @elseif($withdrawal->momo_network === 'TELECEL')
                                <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-red-500 text-white text-xs font-bold">TEL</span>
                            @elseif($withdrawal->momo_network === 'AirtelTigo')
                                <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-gradient-to-r from-red-500 to-blue-500 text-white text-xs font-bold">AT</span>
                            @else
                                <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-gray-200 text-gray-600 text-xs font-bold">?</span>
                            @endif
                            <div>
                                <p class="text-sm font-mono font-medium text-gray-900">{{ $withdrawal->momo_number ?? 'N/A' }}</p>
                                <p class="text-xs text-gray-500">{{ $withdrawal->momo_network ?? 'Not specified' }}</p>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-semibold text-gray-900">GHS {{ number_format($withdrawal->amount, 2) }}</div>
                    </td>
                    <td class="px-6 py-4">
                        <div>
                            @switch($withdrawal->status)
                                @case(\App\Models\VendorWithdrawal::STATUS_APPROVED)
                                    <x-badge variant="completed">Approved</x-badge>
                                    @break
                                @case(\App\Models\VendorWithdrawal::STATUS_REJECTED)
                                    <x-badge variant="warning">Rejected</x-badge>
                                    @break
                                @case(\App\Models\VendorWithdrawal::STATUS_PROCESSING)
                                    <x-badge variant="processing">Processing</x-badge>
                                    @break
                                @default
                                    <x-badge variant="pending">Pending</x-badge>
                            @endswitch
                        </div>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-500">
                        <span class="font-mono text-xs bg-gray-100 px-2 py-1 rounded">{{ $withdrawal->reference }}</span>
                        @if($withdrawal->payout_reference)
                            <p class="text-xs text-green-600 mt-1 font-mono">
                                Payout: {{ $withdrawal->payout_reference }}
                            </p>
                        @endif
                        @if($withdrawal->paid_at)
                            <p class="text-xs text-gray-400 mt-1">
                                Paid: {{ $withdrawal->paid_at->format('M d, Y H:i') }}
                            </p>
                        @endif
                        @if($withdrawal->notes)
                            <p class="text-xs text-gray-400 mt-1 max-w-[150px] truncate" title="{{ $withdrawal->notes }}">{{ $withdrawal->notes }}</p>
                        @endif
                    </td>
                    <!-- Actions column removed -->
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500">No withdrawals match this filter.</td>
                </tr>
            @endforelse
        </x-table>

        <div class="flex justify-end">
            {{ $withdrawals->links() }}
        </div>
    </div>
</x-admin-layout>
@endsection
