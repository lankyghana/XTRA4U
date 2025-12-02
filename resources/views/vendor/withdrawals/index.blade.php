@extends('layouts.vendor')

@section('title', 'Withdrawals - XTRA4U')
@section('description', 'Track all payout requests and submit new vendor withdrawals')

@section('content')
<x-vendor-layout title="Withdrawals" subtitle="Track payout history and request new disbursements" active="withdrawals">
    <x-slot name="actions">
        <x-button href="{{ route('storefront.vendor', ['vendor' => $vendor]) }}" variant="outline" size="sm">
            View Storefront
        </x-button>
    </x-slot>

    <div class="space-y-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <x-card variant="metric" padding="md">
                <div>
                    <p class="text-sm text-gray-500">Withdrawable Balance</p>
                    <p class="text-2xl font-semibold text-gray-900 mt-1">GHS {{ number_format($withdrawableBalance, 2) }}</p>
                    <p class="text-xs text-gray-400 mt-2">Includes completed earnings minus pending payouts.</p>
                </div>
            </x-card>

            <x-card variant="metric" padding="md">
                <div>
                    <p class="text-sm text-gray-500">Pending Requests</p>
                    <p class="text-2xl font-semibold text-amber-600 mt-1">GHS {{ number_format($pendingTotal, 2) }}</p>
                    <p class="text-xs text-gray-400 mt-2">Awaiting finance review.</p>
                </div>
            </x-card>

            <x-card variant="metric" padding="md">
                <div>
                    <p class="text-sm text-gray-500">Approved To Date</p>
                    <p class="text-2xl font-semibold text-brand-green mt-1">GHS {{ number_format($approvedTotal, 2) }}</p>
                    <p class="text-xs text-gray-400 mt-2">Total paid out successfully.</p>
                </div>
            </x-card>
        </div>

        <x-card>
            <div class="px-4 py-5 sm:p-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-4">Request a Withdrawal</h2>

                @if (session('status'))
                    <div class="mb-4 bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg">
                        {{ session('status') }}
                    </div>
                @endif

                <form method="POST" action="{{ route('vendor.withdrawals.store') }}" class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    @csrf
                    <div>
                        <label for="withdraw_amount" class="block text-sm font-medium text-gray-700">Withdrawal Amount</label>
                        <input
                            type="number"
                            step="0.01"
                            min="1"
                            max="{{ $withdrawableBalance }}"
                            name="withdraw_amount"
                            id="withdraw_amount"
                            value="{{ old('withdraw_amount') }}"
                            class="mt-1 block w-full rounded-md border border-gray-300 shadow-sm focus:border-brand-bright-blue focus:ring-brand-bright-blue"
                            placeholder="e.g. 250.00"
                            {{ $withdrawableBalance <= 0 ? 'disabled' : '' }}
                        >
                        @error('withdraw_amount')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="notes" class="block text-sm font-medium text-gray-700">Notes (optional)</label>
                        <textarea
                            id="notes"
                            name="notes"
                            rows="3"
                            class="mt-1 block w-full rounded-md border border-gray-300 shadow-sm focus:border-brand-bright-blue focus:ring-brand-bright-blue"
                            placeholder="Add payout instructions or bank details..."
                        >{{ old('notes') }}</textarea>
                        @error('notes')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="lg:col-span-2 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                        <p class="text-sm text-gray-500">Maximum withdrawable right now: <strong>GHS {{ number_format($withdrawableBalance, 2) }}</strong></p>
                        <x-button type="submit" variant="primary" class="justify-center" :disabled="$withdrawableBalance <= 0">
                            Request Withdrawal
                        </x-button>
                    </div>
                </form>
            </div>
        </x-card>

        <x-card>
            <div class="px-4 py-5 sm:p-6">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Withdrawal History</h2>
                        <p class="text-sm text-gray-500">Track every payout request and its status.</p>
                    </div>
                </div>

                <x-table :headers="['Reference', 'Date', 'Amount', 'Status', 'Notes']">
                    @forelse ($withdrawals as $withdrawal)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $withdrawal->reference }}</td>
                            <td class="px-6 py-4 text-sm text-gray-500">{{ $withdrawal->created_at?->format('M d, Y • h:i A') }}</td>
                            <td class="px-6 py-4 text-sm text-gray-900">GHS {{ number_format($withdrawal->amount, 2) }}</td>
                            <td class="px-6 py-4 text-sm">
                                @if ($withdrawal->status === \App\Models\VendorWithdrawal::STATUS_APPROVED)
                                    <x-badge variant="completed">Approved</x-badge>
                                @elseif ($withdrawal->status === \App\Models\VendorWithdrawal::STATUS_PROCESSING)
                                    <x-badge variant="info">Processing</x-badge>
                                @elseif ($withdrawal->status === \App\Models\VendorWithdrawal::STATUS_REJECTED)
                                    <x-badge variant="warning">Rejected</x-badge>
                                @else
                                    <x-badge variant="pending">Pending</x-badge>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500">
                                {{ $withdrawal->notes ?: '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-4 text-center text-sm text-gray-500">No withdrawal activity yet.</td>
                        </tr>
                    @endforelse
                </x-table>

                @if ($withdrawals->hasPages())
                    <div class="mt-4">
                        {{ $withdrawals->links() }}
                    </div>
                @endif
            </div>
        </x-card>
    </div>
</x-vendor-layout>
@endsection
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <x-card variant="metric" padding="md">
                        <div>
                            <p class="text-sm text-gray-500">Withdrawable Balance</p>
                            <p class="text-2xl font-semibold text-gray-900 mt-1">GHS {{ number_format($withdrawableBalance, 2) }}</p>
                            <p class="text-xs text-gray-400 mt-2">Includes completed earnings minus pending payouts.</p>
                        </div>
                    </x-card>

                    <x-card variant="metric" padding="md">
                        <div>
                            <p class="text-sm text-gray-500">Pending Requests</p>
                            <p class="text-2xl font-semibold text-amber-600 mt-1">GHS {{ number_format($pendingTotal, 2) }}</p>
                            <p class="text-xs text-gray-400 mt-2">Awaiting finance review.</p>
                        </div>
                    </x-card>

                    <x-card variant="metric" padding="md">
                        <div>
                            <p class="text-sm text-gray-500">Approved To Date</p>
                            <p class="text-2xl font-semibold text-brand-green mt-1">GHS {{ number_format($approvedTotal, 2) }}</p>
                            <p class="text-xs text-gray-400 mt-2">Total paid out successfully.</p>
                        </div>
                    </x-card>
                </div>

                <x-card>
                    <div class="px-4 py-5 sm:p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-4">Request a Withdrawal</h2>

                        @if (session('status'))
                            <div class="mb-4 bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg">
                                {{ session('status') }}
                            </div>
                        @endif

                        <form method="POST" action="{{ route('vendor.withdrawals.store') }}" class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                            @csrf
                            <div>
                                <label for="withdraw_amount" class="block text-sm font-medium text-gray-700">Withdrawal Amount</label>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="1"
                                    max="{{ $withdrawableBalance }}"
                                    name="withdraw_amount"
                                    id="withdraw_amount"
                                    value="{{ old('withdraw_amount') }}"
                                    class="mt-1 block w-full rounded-md border border-gray-300 shadow-sm focus:border-brand-bright-blue focus:ring-brand-bright-blue"
                                    placeholder="e.g. 250.00"
                                    {{ $withdrawableBalance <= 0 ? 'disabled' : '' }}
                                >
                                @error('withdraw_amount')
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="notes" class="block text-sm font-medium text-gray-700">Notes (optional)</label>
                                <textarea
                                    id="notes"
                                    name="notes"
                                    rows="3"
                                    class="mt-1 block w-full rounded-md border border-gray-300 shadow-sm focus:border-brand-bright-blue focus:ring-brand-bright-blue"
                                    placeholder="Add payout instructions or bank details..."
                                >{{ old('notes') }}</textarea>
                                @error('notes')
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="lg:col-span-2 flex items-center justify-between">
                                <p class="text-sm text-gray-500">Maximum withdrawable right now: <strong>GHS {{ number_format($withdrawableBalance, 2) }}</strong></p>
                                <x-button type="submit" variant="primary" class="justify-center" :disabled="$withdrawableBalance <= 0">
                                    Request Withdrawal
                                </x-button>
                            </div>
                        </form>
                    </div>
                </x-card>

                <x-card>
                    <div class="px-4 py-5 sm:p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <h2 class="text-lg font-semibold text-gray-900">Withdrawal History</h2>
                                <p class="text-sm text-gray-500">Track every payout request and its status.</p>
                            </div>
                        </div>

                        <x-table :headers="['Reference', 'Date', 'Amount', 'Status', 'Notes']">
                            @forelse ($withdrawals as $withdrawal)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $withdrawal->reference }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-500">{{ $withdrawal->created_at?->format('M d, Y • h:i A') }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-900">GHS {{ number_format($withdrawal->amount, 2) }}</td>
                                    <td class="px-6 py-4 text-sm">
                                        @if ($withdrawal->status === \App\Models\VendorWithdrawal::STATUS_APPROVED)
                                            <x-badge variant="completed">Approved</x-badge>
                                        @elseif ($withdrawal->status === \App\Models\VendorWithdrawal::STATUS_PROCESSING)
                                            <x-badge variant="info">Processing</x-badge>
                                        @elseif ($withdrawal->status === \App\Models\VendorWithdrawal::STATUS_REJECTED)
                                            <x-badge variant="warning">Rejected</x-badge>
                                        @else
                                            <x-badge variant="pending">Pending</x-badge>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500">
                                        {{ $withdrawal->notes ?: '—' }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-6 py-4 text-center text-sm text-gray-500">No withdrawal activity yet.</td>
                                </tr>
                            @endforelse
                        </x-table>

                        @if ($withdrawals->hasPages())
                            <div class="mt-4">
                                {{ $withdrawals->links() }}
                            </div>
                        @endif
                    </div>
                </x-card>
            </div>
        </main>
    </div>
</div>
@endsection
