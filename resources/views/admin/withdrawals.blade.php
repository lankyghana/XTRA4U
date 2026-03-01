@extends('layouts.admin')

@section('title', 'Vendor Withdrawals - Admin Portal')

@section('content')
<x-admin-layout title="Vendor Withdrawals" subtitle="Review and action payout requests submitted by approved vendors" active="withdrawals">
    <div class="space-y-6">
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl px-5 py-4 shadow-lg transform hover:scale-105 transition-transform duration-200">
                <p class="text-xs uppercase text-blue-100 font-medium">Processing</p>
                <p class="text-2xl font-bold text-white mt-1">{{ $summary['processing'] }}</p>
                <p class="text-xs text-blue-100 mt-1">GHS {{ number_format($summary['processing_amount'], 2) }}</p>
            </div>
            <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-xl px-5 py-4 shadow-lg transform hover:scale-105 transition-transform duration-200">
                <p class="text-xs uppercase text-green-100 font-medium">Approved</p>
                <p class="text-2xl font-bold text-white mt-1">{{ $summary['approved'] }}</p>
            </div>
            <div class="bg-gradient-to-br from-red-500 to-red-600 rounded-xl px-5 py-4 shadow-lg transform hover:scale-105 transition-transform duration-200">
                <p class="text-xs uppercase text-red-100 font-medium">Failed</p>
                <p class="text-2xl font-bold text-white mt-1">{{ $summary['failed'] }}</p>
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

        <div class="flex flex-wrap gap-3 items-center justify-between">
            <form method="GET" action="{{ route('admin.withdrawals.index') }}" class="flex items-center gap-2 w-full md:w-auto">
                <input
                    type="text"
                    name="q"
                    value="{{ $search ?? '' }}"
                    placeholder="Search vendor, reference, MoMo number"
                    class="w-full md:w-72 px-3 py-2 border border-gray-200 rounded-lg shadow-sm focus:border-brand-deep-blue focus:ring-brand-deep-blue text-sm"
                >
                @if(($statusFilter ?? '') !== '')
                    <input type="hidden" name="status" value="{{ $statusFilter }}">
                @endif
                <x-button type="submit" variant="primary" size="sm">Search</x-button>
                @if(($search ?? '') !== '')
                    <a href="{{ route('admin.withdrawals.index', array_filter(['status' => $statusFilter])) }}" class="text-sm text-gray-500 hover:text-gray-700">Clear</a>
                @endif
            </form>

            @php
                $filters = [
                    '' => 'All',
                    \App\Models\VendorWithdrawal::STATUS_PROCESSING => 'Processing',
                    \App\Models\VendorWithdrawal::STATUS_APPROVED => 'Approved',
                    \App\Models\VendorWithdrawal::STATUS_FAILED => 'Failed',
                ];
            @endphp
            <div class="flex flex-wrap gap-2">
                @foreach ($filters as $value => $label)
                    <a href="{{ route('admin.withdrawals.index', array_filter(['status' => $value, 'q' => $search ?? null])) }}"
                       class="px-3 py-2 rounded-lg text-sm font-medium {{ ($statusFilter ?? '') === $value ? 'bg-brand-deep-blue text-white' : 'text-gray-600 border border-gray-200' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </div>

        <x-table :headers="['Vendor', 'MoMo Details', 'Amount', 'Status', 'Gateway', 'References', 'Timestamps', 'Error', 'Actions']">
            @forelse ($withdrawals as $withdrawal)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium text-gray-900">{{ $withdrawal->vendor?->name ?? 'Unknown vendor' }}</div>
                        <div class="text-sm text-gray-500">{{ $withdrawal->vendor?->email ?? ('Vendor ID: ' . $withdrawal->vendor_id) }}</div>
                        <div class="text-xs text-gray-400 mt-1">{{ $withdrawal->created_at?->format('M d, Y • H:i') }}</div>
                    </td>
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-2">
                            @php
                                $network = config('momo.withdrawal_networks.' . ($withdrawal->momo_network ?? ''), null);
                            @endphp
                            @if ($network)
                                <span class="inline-flex items-center justify-center w-8 h-8 rounded-full {{ $network['admin']['badge_class'] ?? 'bg-gray-200 text-gray-600' }} text-xs font-bold">{{ $network['admin']['badge_label'] ?? '?' }}</span>
                            @else
                                <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-gray-200 text-gray-600 text-xs font-bold">?</span>
                            @endif
                            <div>
                                <p class="text-sm font-mono font-medium text-gray-900">{{ $withdrawal->momo_number ?? 'N/A' }}</p>
                                <p class="text-xs text-gray-500">
                                    {{ $withdrawal->momo_network ?? 'Not specified' }}
                                    @if(!empty($withdrawal->momo_account_type))
                                        • {{ $withdrawal->momo_account_type }}
                                    @endif
                                </p>
                                @if(!empty($withdrawal->momo_account_name))
                                    <p class="text-xs text-gray-600">Name: {{ $withdrawal->momo_account_name }}</p>
                                @endif
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
                                @case(\App\Models\VendorWithdrawal::STATUS_FAILED)
                                    <x-badge variant="warning">Failed</x-badge>
                                    @break
                                @case(\App\Models\VendorWithdrawal::STATUS_PROCESSING)
                                    <x-badge variant="processing">Processing</x-badge>
                                    @break
                                @default
                                    <x-badge variant="pending">Unknown</x-badge>
                            @endswitch
                        </div>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-600">
                        <span class="text-xs bg-gray-100 px-2 py-1 rounded">{{ $withdrawal->payout_gateway ?? 'N/A' }}</span>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-500">
                        <p class="font-mono text-xs bg-gray-100 px-2 py-1 rounded inline-block">{{ $withdrawal->reference }}</p>
                        @if($withdrawal->payout_reference)
                            <p class="text-xs text-green-600 mt-1 font-mono">Payout: {{ $withdrawal->payout_reference }}</p>
                        @endif
                        @if($withdrawal->payout_transaction_id)
                            <p class="text-xs text-gray-500 mt-1 font-mono">Txn: {{ $withdrawal->payout_transaction_id }}</p>
                        @endif
                    </td>
                    <td class="px-6 py-4 text-xs text-gray-500">
                        <p>Created: {{ $withdrawal->created_at?->format('M d, Y H:i') }}</p>
                        @if($withdrawal->paid_at)
                            <p>Paid: {{ $withdrawal->paid_at->format('M d, Y H:i') }}</p>
                        @endif
                    </td>
                    <td class="px-6 py-4 text-xs text-gray-500">
                        @if($withdrawal->error_message)
                            <span class="text-red-600" title="{{ $withdrawal->error_message }}">{{ \Illuminate\Support\Str::limit($withdrawal->error_message, 80) }}</span>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        @php
                            $isProcessing = $withdrawal->status === \App\Models\VendorWithdrawal::STATUS_PROCESSING;
                            $cooldownActive = $withdrawal->payout_attempted_at && $withdrawal->payout_attempted_at->gt(now()->subSeconds(60));
                        @endphp

                        @if($isProcessing)
                            <form method="POST" action="{{ route('admin.withdrawals.refresh', $withdrawal) }}">
                                @csrf
                                <x-button type="submit" variant="outline" size="sm" :disabled="$cooldownActive" title="{{ $cooldownActive ? 'Please wait a moment before retrying.' : 'Refresh payout status now' }}">
                                    Refresh
                                </x-button>
                            </form>
                            @if($cooldownActive)
                                <p class="text-xs text-gray-400 mt-1">Try again shortly</p>
                            @endif
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="px-6 py-4 text-center text-sm text-gray-500">No withdrawals match this filter.</td>
                </tr>
            @endforelse
        </x-table>

        <div class="flex justify-end">
            {{ $withdrawals->links() }}
        </div>
    </div>
</x-admin-layout>
@endsection
