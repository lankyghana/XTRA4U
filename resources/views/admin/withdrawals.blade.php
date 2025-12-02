@extends('layouts.admin')

@section('title', 'Vendor Withdrawals - Admin Portal')

@section('content')
<x-admin-layout title="Vendor Withdrawals" subtitle="Review and action payout requests submitted by approved vendors" active="withdrawals">
    <div class="space-y-6">
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <div class="bg-white rounded-lg border border-gray-100 px-4 py-3">
                <p class="text-xs uppercase text-gray-500">Pending</p>
                <p class="text-lg font-semibold text-gray-900">{{ $summary['pending'] }}</p>
                <p class="text-xs text-gray-400">GHS {{ number_format($summary['pending_amount'], 2) }}</p>
            </div>
            <div class="bg-white rounded-lg border border-gray-100 px-4 py-3">
                <p class="text-xs uppercase text-gray-500">Processing</p>
                <p class="text-lg font-semibold text-gray-900">{{ $summary['processing'] }}</p>
            </div>
            <div class="bg-white rounded-lg border border-gray-100 px-4 py-3">
                <p class="text-xs uppercase text-gray-500">Approved</p>
                <p class="text-lg font-semibold text-gray-900">{{ $summary['approved'] }}</p>
            </div>
            <div class="bg-white rounded-lg border border-gray-100 px-4 py-3">
                <p class="text-xs uppercase text-gray-500">Rejected</p>
                <p class="text-lg font-semibold text-gray-900">{{ $summary['rejected'] }}</p>
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
            @php($filters = [
                '' => 'All',
                \App\Models\VendorWithdrawal::STATUS_PENDING => 'Pending',
                \App\Models\VendorWithdrawal::STATUS_PROCESSING => 'Processing',
                \App\Models\VendorWithdrawal::STATUS_APPROVED => 'Approved',
                \App\Models\VendorWithdrawal::STATUS_REJECTED => 'Rejected',
            ])
            @foreach ($filters as $value => $label)
                <a href="{{ route('admin.withdrawals.index', array_filter(['status' => $value])) }}"
                   class="px-3 py-2 rounded-lg text-sm font-medium {{ ($statusFilter ?? '') === $value ? 'bg-brand-deep-blue text-white' : 'text-gray-600 border border-gray-200' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>

        <x-table :headers="['Vendor', 'Requested', 'Amount', 'Reference', 'Notes', 'Actions']">
            @forelse ($withdrawals as $withdrawal)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium text-gray-900">{{ $withdrawal->vendor->name }}</div>
                        <div class="text-sm text-gray-500">{{ $withdrawal->vendor->email }}</div>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-500">
                        <div>{{ $withdrawal->created_at?->format('M d, Y') }}</div>
                        <div>{{ $withdrawal->created_at?->format('H:i') }}</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-semibold text-gray-900">GHS {{ number_format($withdrawal->amount, 2) }}</div>
                        <div class="mt-1">
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
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-500">
                        {{ $withdrawal->notes ?? '—' }}
                    </td>
                    <td class="px-6 py-4 text-right">
                        @if (in_array($withdrawal->status, [\App\Models\VendorWithdrawal::STATUS_PENDING, \App\Models\VendorWithdrawal::STATUS_PROCESSING]))
                            <div class="flex flex-col space-y-2">
                                @if ($withdrawal->status === \App\Models\VendorWithdrawal::STATUS_PENDING)
                                    <form method="POST" action="{{ route('admin.withdrawals.processing', $withdrawal) }}">
                                        @csrf
                                        <button type="submit" class="w-full px-3 py-1.5 rounded-lg bg-yellow-100 text-yellow-800 hover:bg-yellow-200 text-xs font-semibold">
                                            Mark Processing
                                        </button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('admin.withdrawals.approve', $withdrawal) }}">
                                    @csrf
                                    <input type="hidden" name="notes" value="{{ $withdrawal->notes }}">
                                    <button type="submit" class="w-full px-3 py-1.5 rounded-lg bg-green-600 text-white hover:bg-green-700 text-xs font-semibold">
                                        Approve
                                    </button>
                                </form>
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
