@extends('layouts.vendor')

@section('title', 'Vendor Dashboard - XTRA4U')
@section('description', 'Manage your vendor account and track your performance')

@section('content')
<x-vendor-layout :vendor="$vendor" title="Dashboard" subtitle="Manage your vendor account and track your performance" active="dashboard">
    <x-slot name="actions">
        <button class="p-2 rounded-full text-gray-400 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-brand-deep-blue" aria-label="Sync data">
            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-5-5 5-5H15" />
            </svg>
        </button>
    </x-slot>

    <div class="grid grid-cols-1 gap-6 mb-8 md:grid-cols-2 lg:grid-cols-4">
        <x-card variant="metric" padding="md">
            <div class="flex items-center">
                <div class="shrink-0">
                    <div class="w-8 h-8 bg-brand-green rounded-lg flex items-center justify-center">
                        <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M4 4h12v12H4z" />
                        </svg>
                    </div>
                </div>
                <div class="ml-5 w-0 flex-1">
                    <dl>
                        <dt class="text-sm font-medium text-gray-500 truncate">Gross Sales</dt>
                        <dd class="text-lg font-semibold text-gray-900">GHS {{ number_format($totalSales, 2) }}</dd>
                    </dl>
                </div>
            </div>
        </x-card>

        <x-card variant="metric" padding="md">
            <div class="flex items-center">
                <div class="shrink-0">
                    <div class="w-8 h-8 bg-brand-bright-blue rounded-lg flex items-center justify-center">
                        <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M10 2a8 8 0 100 16 8 8 0 000-16z" />
                        </svg>
                    </div>
                </div>
                <div class="ml-5 w-0 flex-1">
                    <dl>
                        <dt class="text-sm font-medium text-gray-500 truncate">Total Earnings (Net)</dt>
                        <dd class="text-lg font-semibold text-gray-900">GHS {{ number_format($totalEarnings, 2) }}</dd>
                    </dl>
                </div>
            </div>
        </x-card>

        <x-card variant="metric" padding="md">
            <div class="flex items-center">
                <div class="shrink-0">
                    <div class="w-8 h-8 bg-purple-500 rounded-lg flex items-center justify-center">
                        <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M5 8h10v4H5z" />
                        </svg>
                    </div>
                </div>
                <div class="ml-5 w-0 flex-1">
                    <dl>
                        <dt class="text-sm font-medium text-gray-500 truncate">Withdrawable Balance</dt>
                        <dd class="text-lg font-semibold text-gray-900">GHS {{ number_format($withdrawableBalance, 2) }}</dd>
                    </dl>
                </div>
            </div>
        </x-card>

        <x-card variant="metric" padding="md">
            <div class="flex items-center">
                <div class="shrink-0">
                    <div class="w-8 h-8 bg-yellow-500 rounded-lg flex items-center justify-center">
                        <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M4 4h12v12H4z" />
                        </svg>
                    </div>
                </div>
                <div class="ml-5 w-0 flex-1">
                    <dl>
                        <dt class="text-sm font-medium text-gray-500 truncate">Commission Paid</dt>
                        <dd class="text-lg font-semibold text-gray-900">GHS {{ number_format($commissions, 2) }}</dd>
                    </dl>
                </div>
            </div>
        </x-card>
    </div>

    <div class="grid grid-cols-1 gap-6 mb-8 lg:grid-cols-3">
        <x-card class="lg:col-span-2">
            <div class="px-4 py-5 sm:p-6">
                <h3 class="text-lg leading-6 font-medium text-gray-900">Wallet & Withdrawals</h3>
                <p class="text-sm text-gray-500 mb-4">You keep 99% of every sale. 1% is deducted automatically for platform fees.</p>

                @if (session('status'))
                    <div class="mb-4 bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg">
                        {{ session('status') }}
                    </div>
                @endif

                <form method="POST" action="{{ route('vendor.withdrawals.store') }}" class="space-y-4">
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
                            placeholder="e.g. 120.00"
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
                            rows="2"
                            class="mt-1 block w-full rounded-md border border-gray-300 shadow-sm focus:border-brand-bright-blue focus:ring-brand-bright-blue"
                            placeholder="Add payout instructions or bank details..."
                        >{{ old('notes') }}</textarea>
                    </div>

                    <x-button type="submit" variant="primary" class="w-full justify-center" :disabled="$withdrawableBalance <= 0">
                        Request Withdrawal
                    </x-button>
                    <p class="text-xs text-gray-500">Maximum withdrawable today: <strong>GHS {{ number_format($withdrawableBalance, 2) }}</strong></p>
                </form>
            </div>
        </x-card>

        <x-card>
            <div class="px-4 py-5 sm:p-6">
                <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4">Recent Withdrawals</h3>
                <div class="space-y-4">
                    @forelse ($recentWithdrawals as $withdrawal)
                        <div class="p-3 rounded-lg border border-gray-100">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-semibold text-gray-900">GHS {{ number_format($withdrawal->amount, 2) }}</p>
                                    <p class="text-xs text-gray-500">{{ $withdrawal->created_at?->diffForHumans() }}</p>
                                </div>
                                @if ($withdrawal->status === 'approved')
                                    <x-badge variant="completed">Approved</x-badge>
                                @elseif ($withdrawal->status === 'rejected')
                                    <x-badge variant="warning">Rejected</x-badge>
                                @else
                                    <x-badge variant="pending">Pending</x-badge>
                                @endif
                            </div>
                            <p class="text-xs text-gray-500 mt-1">Ref: {{ $withdrawal->reference }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">No withdrawal history yet.</p>
                    @endforelse
                </div>
            </div>
        </x-card>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-card>
            <div class="px-4 py-5 sm:p-6">
                <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4">Recent Orders</h3>
                <x-table :headers="['Order ID', 'Recipient', 'Amount', 'Status']">
                    @forelse ($orders->take(5) as $order)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 text-sm font-medium text-gray-900">#{{ $order->id }}</td>
                            <td class="px-6 py-4 text-sm text-gray-900">{{ $order->recipient_phone_number }}</td>
                            <td class="px-6 py-4 text-sm text-gray-900">GHS {{ number_format($order->amount_paid, 2) }}</td>
                            <td class="px-6 py-4 text-sm">
                                <x-badge :variant="$order->status === 'Completed' ? 'completed' : 'pending'">{{ $order->status }}</x-badge>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-4 text-center text-sm text-gray-500">No orders yet.</td>
                        </tr>
                    @endforelse
                </x-table>
                <div class="mt-4">
                    <x-button href="{{ route('vendor.orders.index') }}" variant="outline" size="sm">
                        View All Orders
                    </x-button>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="px-4 py-5 sm:p-6">
                <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4">Quick Actions</h3>
                <div class="space-y-3">
                    <x-button href="{{ route('vendor.products.create') }}" variant="primary" class="w-full justify-center">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                        </svg>
                        Add New Product
                    </x-button>

                    <x-button href="{{ route('vendor.orders.index') }}" variant="outline" class="w-full justify-center">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                        </svg>
                        Process Orders
                    </x-button>

                    <x-button href="#" variant="outline" class="w-full justify-center">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                        </svg>
                        View Analytics
                    </x-button>
                </div>
            </div>
        </x-card>
    </div>
</x-vendor-layout>
@endsection