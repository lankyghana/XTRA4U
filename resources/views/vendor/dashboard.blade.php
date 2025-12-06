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
        <!-- Gross Sales Card -->
        <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-xl shadow-lg overflow-hidden transform hover:scale-105 transition-transform duration-200">
            <div class="px-5 py-5">
                <div class="flex items-center">
                    <div class="shrink-0">
                        <div class="w-12 h-12 bg-white/20 backdrop-blur-sm rounded-xl flex items-center justify-center">
                            <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M4 4a2 2 0 00-2 2v4a2 2 0 002 2V6h10a2 2 0 00-2-2H4zm2 6a2 2 0 012-2h8a2 2 0 012 2v4a2 2 0 01-2 2H8a2 2 0 01-2-2v-4zm6 4a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd" />
                            </svg>
                        </div>
                    </div>
                    <div class="ml-4 w-0 flex-1">
                        <dl>
                            <dt class="text-sm font-medium text-green-100 truncate">Gross Sales</dt>
                            <dd class="text-xl font-bold text-white mt-1">GHS {{ number_format($totalSales, 2) }}</dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>

        <!-- Total Earnings Card -->
        <div class="bg-gradient-to-br from-brand-deep-blue to-brand-bright-blue rounded-xl shadow-lg overflow-hidden transform hover:scale-105 transition-transform duration-200">
            <div class="px-5 py-5">
                <div class="flex items-center">
                    <div class="shrink-0">
                        <div class="w-12 h-12 bg-white/20 backdrop-blur-sm rounded-xl flex items-center justify-center">
                            <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M8.433 7.418c.155-.103.346-.196.567-.267v1.698a2.305 2.305 0 01-.567-.267C8.07 8.34 8 8.114 8 8c0-.114.07-.34.433-.582zM11 12.849v-1.698c.22.071.412.164.567.267.364.243.433.468.433.582 0 .114-.07.34-.433.582a2.305 2.305 0 01-.567.267z" />
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-13a1 1 0 10-2 0v.092a4.535 4.535 0 00-1.676.662C6.602 6.234 6 7.009 6 8c0 .99.602 1.765 1.324 2.246.48.32 1.054.545 1.676.662v1.941c-.391-.127-.68-.317-.843-.504a1 1 0 10-1.51 1.31c.562.649 1.413 1.076 2.353 1.253V15a1 1 0 102 0v-.092a4.535 4.535 0 001.676-.662C13.398 13.766 14 12.991 14 12c0-.99-.602-1.765-1.324-2.246A4.535 4.535 0 0011 9.092V7.151c.391.127.68.317.843.504a1 1 0 101.511-1.31c-.563-.649-1.413-1.076-2.354-1.253V5z" clip-rule="evenodd" />
                            </svg>
                        </div>
                    </div>
                    <div class="ml-4 w-0 flex-1">
                        <dl>
                            <dt class="text-sm font-medium text-blue-100 truncate">Total Earnings (Net)</dt>
                            <dd class="text-xl font-bold text-white mt-1">GHS {{ number_format($totalEarnings, 2) }}</dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>

        <!-- Withdrawable Balance Card -->
        <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl shadow-lg overflow-hidden transform hover:scale-105 transition-transform duration-200">
            <div class="px-5 py-5">
                <div class="flex items-center">
                    <div class="shrink-0">
                        <div class="w-12 h-12 bg-white/20 backdrop-blur-sm rounded-xl flex items-center justify-center">
                            <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M4 4a2 2 0 00-2 2v1h16V6a2 2 0 00-2-2H4z" />
                                <path fill-rule="evenodd" d="M18 9H2v5a2 2 0 002 2h12a2 2 0 002-2V9zM4 13a1 1 0 011-1h1a1 1 0 110 2H5a1 1 0 01-1-1zm5-1a1 1 0 100 2h1a1 1 0 100-2H9z" clip-rule="evenodd" />
                            </svg>
                        </div>
                    </div>
                    <div class="ml-4 w-0 flex-1">
                        <dl>
                            <dt class="text-sm font-medium text-purple-100 truncate">Withdrawable Balance</dt>
                            <dd class="text-xl font-bold text-white mt-1">GHS {{ number_format($withdrawableBalance, 2) }}</dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>

        <!-- Commission Paid Card -->
        <div class="bg-gradient-to-br from-yellow-500 to-yellow-600 rounded-xl shadow-lg overflow-hidden transform hover:scale-105 transition-transform duration-200">
            <div class="px-5 py-5">
                <div class="flex items-center">
                    <div class="shrink-0">
                        <div class="w-12 h-12 bg-white/20 backdrop-blur-sm rounded-xl flex items-center justify-center">
                            <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M3 3a1 1 0 000 2v8a2 2 0 002 2h2.586l-1.293 1.293a1 1 0 101.414 1.414L10 15.414l2.293 2.293a1 1 0 001.414-1.414L12.414 15H15a2 2 0 002-2V5a1 1 0 100-2H3zm11.707 4.707a1 1 0 00-1.414-1.414L10 9.586 8.707 8.293a1 1 0 00-1.414 0l-2 2a1 1 0 101.414 1.414L8 10.414l1.293 1.293a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                            </svg>
                        </div>
                    </div>
                    <div class="ml-4 w-0 flex-1">
                        <dl>
                            <dt class="text-sm font-medium text-yellow-100 truncate">Commission Paid</dt>
                            <dd class="text-xl font-bold text-white mt-1">GHS {{ number_format($commissions, 2) }}</dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 mb-8 lg:grid-cols-3">
        <div class="lg:col-span-2 bg-gradient-to-br from-purple-50 to-blue-50 rounded-xl shadow-lg border border-purple-100">
            <div class="px-6 py-6">
                <h3 class="text-lg leading-6 font-bold text-gray-900">Wallet & Withdrawals</h3>
                <p class="text-sm text-gray-600 mb-6">You keep 99% of every sale. 1% is deducted automatically for platform fees.</p>

                @if (session('status'))
                    <div class="mb-4 bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg">
                        {{ session('status') }}
                    </div>
                @endif

                <form method="POST" action="{{ route('vendor.withdrawals.store') }}" class="space-y-4">
                    @csrf
                    <div>
                        <label for="withdraw_amount" class="block text-sm font-medium text-gray-700 mb-2">Withdrawal Amount</label>
                        <input
                            type="number"
                            step="0.01"
                            min="1"
                            max="{{ $withdrawableBalance }}"
                            name="withdraw_amount"
                            id="withdraw_amount"
                            value="{{ old('withdraw_amount') }}"
                            class="mt-1 block w-full rounded-lg border border-gray-300 shadow-sm focus:border-brand-bright-blue focus:ring-brand-bright-blue px-4 py-3"
                            placeholder="e.g. 120.00"
                            {{ $withdrawableBalance <= 0 ? 'disabled' : '' }}
                        >
                        @error('withdraw_amount')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="momo_number" class="block text-sm font-medium text-gray-700 mb-2">Mobile Money Number <span class="text-red-500">*</span></label>
                        <input
                            type="tel"
                            name="momo_number"
                            id="momo_number"
                            value="{{ old('momo_number') }}"
                            class="mt-1 block w-full rounded-lg border border-gray-300 shadow-sm focus:border-brand-bright-blue focus:ring-brand-bright-blue px-4 py-3"
                            placeholder="0241234567"
                            pattern="0[235][0-9]{8}"
                            maxlength="10"
                            required
                            {{ $withdrawableBalance <= 0 ? 'disabled' : '' }}
                        >
                        @error('momo_number')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Network <span class="text-red-500">*</span></label>
                        <div class="grid grid-cols-3 gap-2">
                            <label class="relative cursor-pointer">
                                <input type="radio" name="momo_network" value="MTN" class="peer sr-only" {{ old('momo_network') === 'MTN' ? 'checked' : '' }} {{ $withdrawableBalance <= 0 ? 'disabled' : '' }} required>
                                <div class="flex flex-col items-center justify-center p-3 rounded-lg border-2 border-gray-200 bg-white peer-checked:border-yellow-500 peer-checked:bg-yellow-50 hover:bg-gray-50 transition-all">
                                    <div class="w-8 h-8 rounded-full bg-yellow-400 flex items-center justify-center mb-1">
                                        <span class="text-xs font-bold text-yellow-900">MTN</span>
                                    </div>
                                    <span class="text-xs font-medium text-gray-700">MTN</span>
                                </div>
                            </label>
                            <label class="relative cursor-pointer">
                                <input type="radio" name="momo_network" value="TELECEL" class="peer sr-only" {{ old('momo_network') === 'TELECEL' ? 'checked' : '' }} {{ $withdrawableBalance <= 0 ? 'disabled' : '' }}>
                                <div class="flex flex-col items-center justify-center p-3 rounded-lg border-2 border-gray-200 bg-white peer-checked:border-red-500 peer-checked:bg-red-50 hover:bg-gray-50 transition-all">
                                    <div class="w-8 h-8 rounded-full bg-red-500 flex items-center justify-center mb-1">
                                        <span class="text-xs font-bold text-white">TE</span>
                                    </div>
                                    <span class="text-xs font-medium text-gray-700">Telecel</span>
                                </div>
                            </label>
                            <label class="relative cursor-pointer">
                                <input type="radio" name="momo_network" value="AirtelTigo" class="peer sr-only" {{ old('momo_network') === 'AirtelTigo' ? 'checked' : '' }} {{ $withdrawableBalance <= 0 ? 'disabled' : '' }}>
                                <div class="flex flex-col items-center justify-center p-3 rounded-lg border-2 border-gray-200 bg-white peer-checked:border-blue-500 peer-checked:bg-blue-50 hover:bg-gray-50 transition-all">
                                    <div class="w-8 h-8 rounded-full bg-gradient-to-r from-red-500 to-blue-500 flex items-center justify-center mb-1">
                                        <span class="text-xs font-bold text-white">AT</span>
                                    </div>
                                    <span class="text-xs font-medium text-gray-700">AirtelTigo</span>
                                </div>
                            </label>
                        </div>
                        @error('momo_network')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="notes" class="block text-sm font-medium text-gray-700 mb-2">Notes (optional)</label>
                        <textarea
                            id="notes"
                            name="notes"
                            rows="3"
                            class="mt-1 block w-full rounded-lg border border-gray-300 shadow-sm focus:border-brand-bright-blue focus:ring-brand-bright-blue px-4 py-3"
                            placeholder="Add payout instructions or bank details..."
                        >{{ old('notes') }}</textarea>
                    </div>

                    <x-button type="submit" variant="primary" class="w-full justify-center" :disabled="$withdrawableBalance <= 0">
                        Request Withdrawal
                    </x-button>
                    <p class="text-xs text-gray-500">Maximum withdrawable today: <strong>GHS {{ number_format($withdrawableBalance, 2) }}</strong></p>
                </form>
            </div>
        </div>

        <div class="bg-gradient-to-br from-gray-50 to-slate-100 rounded-xl shadow-lg border border-gray-200">
            <div class="px-6 py-6">
                <h3 class="text-lg leading-6 font-bold text-gray-900 mb-4">Recent Withdrawals</h3>
                <div class="space-y-3">
                    @forelse ($recentWithdrawals as $withdrawal)
                        <div class="p-4 rounded-lg bg-white border border-gray-200 hover:shadow-md transition-shadow duration-200">
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
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div class="bg-gradient-to-br from-white to-gray-50 rounded-xl shadow-lg border border-gray-200">
            <div class="px-6 py-6">
                <h3 class="text-lg leading-6 font-bold text-gray-900 mb-4">Recent Orders</h3>
                <div class="overflow-hidden rounded-lg border border-gray-200">
                    <x-table :headers="['Order ID', 'Recipient', 'Amount', 'Status']">
                        @forelse ($orders->take(5) as $order)
                            <tr class="hover:bg-gray-50 transition-colors duration-150">
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
                </div>
                <div class="mt-4">
                    <x-button href="{{ route('vendor.orders.index') }}" variant="outline" size="sm">
                        View All Orders
                    </x-button>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-br from-brand-deep-blue to-brand-bright-blue rounded-xl shadow-lg overflow-hidden">
            <div class="px-6 py-6">
                <h3 class="text-lg leading-6 font-bold text-white mb-4">Quick Actions</h3>
                <div class="space-y-3">
                    <a href="{{ route('vendor.products.create') }}" class="flex items-center justify-center w-full bg-white text-brand-deep-blue hover:bg-gray-50 font-semibold py-3 px-4 rounded-lg transition-all duration-200 hover:scale-105 shadow-md">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                        </svg>
                        Add New Product
                    </a>

                    <a href="{{ route('vendor.orders.index') }}" class="flex items-center justify-center w-full bg-white/10 backdrop-blur-sm text-white hover:bg-white/20 font-semibold py-3 px-4 rounded-lg transition-all duration-200 hover:scale-105 border border-white/20">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                        </svg>
                        Process Orders
                    </a>

                    <a href="{{ route('vendor.analytics.index') }}" class="flex items-center justify-center w-full bg-white/10 backdrop-blur-sm text-white hover:bg-white/20 font-semibold py-3 px-4 rounded-lg transition-all duration-200 hover:scale-105 border border-white/20">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                        </svg>
                        View Analytics
                    </a>
                </div>
            </div>
        </div>
    </div>
</x-vendor-layout>
@endsection