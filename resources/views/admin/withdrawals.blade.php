@extends('layouts.admin')

@section('title', 'Vendor Withdrawals - Admin Portal')

@section('content')
<x-admin-layout title="Vendor Withdrawals" subtitle="Review and action payout requests submitted by approved vendors" active="withdrawals">
    <div class="space-y-6">
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <x-admin.stat label="Processing" value="{{ $summary['processing'] }}" hint="GHS {{ number_format($summary['processing_amount'], 2) }}" tone="brand" />
            <x-admin.stat label="Approved" value="{{ $summary['approved'] }}" tone="success" />
            <x-admin.stat label="Failed" value="{{ $summary['failed'] }}" tone="danger" />
            <x-admin.stat label="Cancelled" value="{{ $summary['cancelled'] }}" tone="neutral" />
        </div>

        @if (session('status'))
            <div role="status" class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('status') }}</div>
        @endif

        @error('withdrawal')
            <div role="alert" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div>
        @enderror

        @php
            $tabs = [
                '' => 'All',
                \App\Models\VendorWithdrawal::STATUS_PROCESSING => 'Processing',
                \App\Models\VendorWithdrawal::STATUS_APPROVED => 'Approved',
                \App\Models\VendorWithdrawal::STATUS_FAILED => 'Failed',
                \App\Models\VendorWithdrawal::STATUS_CANCELLED => 'Cancelled',
            ];
        @endphp
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <x-admin.tabs :items="collect($tabs)->map(fn ($label, $value) => [
                'label' => $label,
                'href' => route('admin.withdrawals.index', array_filter(['status' => $value, 'q' => $search ?? null])),
                'active' => ($statusFilter ?? '') === (string) $value,
            ])->values()->all()" />
            <x-admin.filters :action="route('admin.withdrawals.index')" :search="$search ?? ''" placeholder="Search vendor, reference, MoMo number" :hidden="['status' => $statusFilter ?? '']" :resetUrl="route('admin.withdrawals.index', array_filter(['status' => $statusFilter ?? '']))" />
        </div>

        <x-admin.table :headers="['Vendor', 'MoMo Details', 'Amount', 'Status', 'Gateway', 'References', 'Timestamps', 'Error', 'Actions']">
            @forelse ($withdrawals as $withdrawal)
                <tr>
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
                            <x-admin.status :status="$withdrawal->status" />
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
                            <div class="flex flex-col gap-2">
                                <form method="POST" action="{{ route('admin.withdrawals.refresh', $withdrawal) }}">
                                    @csrf
                                    <x-button type="submit" variant="outline" size="sm" :disabled="$cooldownActive" title="{{ $cooldownActive ? 'Please wait a moment before retrying.' : 'Refresh payout status now' }}">
                                        Refresh
                                    </x-button>
                                </form>
                                <form method="POST" action="{{ route('admin.withdrawals.cancel', $withdrawal) }}" class="space-y-1" onsubmit="return confirm('Cancel and refund this withdrawal? This cannot be undone.');">
                                    @csrf
                                    <input type="text" name="note" required minlength="5" maxlength="500" placeholder="Reason for cancellation" class="w-full px-3 py-2 border border-gray-200 rounded-md text-sm focus:ring-brand-violet focus:border-brand-violet">
                                    <x-button type="submit" variant="danger" size="sm">Cancel & Refund</x-button>
                                    <p class="text-[11px] text-red-600">This action cannot be undone.</p>
                                </form>
                                @if($cooldownActive)
                                    <p class="text-xs text-gray-400">Try again shortly</p>
                                @endif
                            </div>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9"><x-admin.empty title="No withdrawals match this filter" description="Try another status or clear the search." /></td>
                </tr>
            @endforelse
        </x-admin.table>

        <div class="flex justify-end">
            {{ $withdrawals->links() }}
        </div>
    </div>
</x-admin-layout>
@endsection
