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

        <x-table :headers="['Vendor', 'MoMo Details', 'Amount', 'Status', 'Reference', 'Actions']">
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
                    <td class="px-6 py-4 text-right">
                        @if (in_array($withdrawal->status, [\App\Models\VendorWithdrawal::STATUS_PENDING, \App\Models\VendorWithdrawal::STATUS_PROCESSING]))
                            <div x-data="{ showApproveModal: false }" class="flex flex-col space-y-2">
                                @if ($withdrawal->status === \App\Models\VendorWithdrawal::STATUS_PENDING)
                                    <form method="POST" action="{{ route('admin.withdrawals.processing', $withdrawal) }}">
                                        @csrf
                                        <button type="submit" class="w-full px-3 py-1.5 rounded-lg bg-yellow-100 text-yellow-800 hover:bg-yellow-200 text-xs font-semibold">
                                            Mark Processing
                                        </button>
                                    </form>
                                @endif
                                
                                <!-- Approve Button (opens modal) -->
                                <button @click="showApproveModal = true" type="button" class="w-full px-3 py-1.5 rounded-lg bg-green-600 text-white hover:bg-green-700 text-xs font-semibold">
                                    Approve
                                </button>
                                
                                <!-- Approve Modal -->
                                <div x-show="showApproveModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
                                    <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                                        <div x-show="showApproveModal" @click="showApproveModal = false" class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"></div>
                                        <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
                                        <div x-show="showApproveModal" 
                                             x-transition:enter="ease-out duration-300"
                                             x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                                             x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                                             class="inline-block align-bottom bg-white rounded-lg px-4 pt-5 pb-4 text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full sm:p-6">
                                            <div>
                                                <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-green-100">
                                                    <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                                    </svg>
                                                </div>
                                                <div class="mt-3 text-center sm:mt-5">
                                                    <h3 class="text-lg leading-6 font-medium text-gray-900">Approve Withdrawal</h3>
                                                    <div class="mt-2">
                                                        <p class="text-sm text-gray-500">
                                                            <strong>{{ $withdrawal->vendor->name }}</strong><br>
                                                            Amount: <strong>GHS {{ number_format($withdrawal->amount, 2) }}</strong><br>
                                                            MoMo: <strong>{{ $withdrawal->momo_number }}</strong> ({{ $withdrawal->momo_network }})
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="mt-5 sm:mt-6 space-y-3">
                                                <!-- Manual Approval -->
                                                <form method="POST" action="{{ route('admin.withdrawals.approve', $withdrawal) }}">
                                                    @csrf
                                                    <input type="hidden" name="automatic_payout" value="0">
                                                    <input type="text" name="manual_reference" placeholder="Transaction Reference (optional)" 
                                                           class="w-full mb-2 px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
                                                    <button type="submit" class="w-full inline-flex justify-center items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                                                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                        </svg>
                                                        Manual Approve (Send money yourself)
                                                    </button>
                                                </form>
                                                
                                                @if($payoutGateway && $payoutGateway->isConfigured())
                                                    <!-- Automatic Payout -->
                                                    <form method="POST" action="{{ route('admin.withdrawals.approve', $withdrawal) }}">
                                                        @csrf
                                                        <input type="hidden" name="automatic_payout" value="1">
                                                        <button type="submit" class="w-full inline-flex justify-center items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                                            </svg>
                                                            Auto Pay via {{ ucfirst($payoutGateway->gateway_name) }}
                                                        </button>
                                                    </form>
                                                @else
                                                    <p class="text-xs text-center text-gray-500 bg-gray-100 rounded p-2">
                                                        <a href="{{ route('admin.payment-gateways.index') }}" class="text-blue-600 hover:underline">Configure a payout gateway</a> to enable automatic payments
                                                    </p>
                                                @endif
                                                
                                                <button @click="showApproveModal = false" type="button" class="w-full inline-flex justify-center items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-brand-deep-blue">
                                                    Cancel
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <form method="POST" action="{{ route('admin.withdrawals.reject', $withdrawal) }}" class="flex flex-col space-y-2">
                                    @csrf
                                    <input type="text" name="notes" required placeholder="Reason"
                                           class="px-2 py-1 text-xs border border-gray-200 rounded-lg focus:border-red-300 focus:ring-red-300" />
                                    <button type="submit" class="w-full px-3 py-1.5 rounded-lg bg-red-50 text-red-700 hover:bg-red-100 text-xs font-semibold">
                                        Reject
                                    </button>
                                </form>
                            </div>
                        @else
                            <span class="text-xs text-gray-400">No actions available</span>
                        @endif
                    </td>
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
