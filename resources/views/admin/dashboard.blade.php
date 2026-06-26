@extends('layouts.admin')

@section('title', 'Admin Dashboard - XTRA4U')
@section('description', 'System administration and management portal')

@section('content')
<x-admin-layout title="System Dashboard" subtitle="System administration and management portal" active="dashboard">
    <x-slot name="actions">
        <span class="hidden sm:inline text-sm text-gray-500">Last updated: {{ now()->format('M d, Y H:i') }}</span>
        <button class="p-2 rounded-full text-gray-400 hover:text-gray-500 focus:ring-2 focus:ring-offset-2 focus:ring-brand-deep-blue" aria-label="Refresh stats">
            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
            </svg>
        </button>
    </x-slot>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-6 mb-8">
        <!-- Total Revenue Card -->
        <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-xl shadow-lg overflow-hidden transform hover:scale-105 transition-transform duration-200">
            <div class="px-6 py-5">
                <div class="flex items-center">
                    <div class="shrink-0">
                        <div class="w-14 h-14 bg-white/20 backdrop-blur-sm rounded-xl flex items-center justify-center">
                            <svg class="w-7 h-7 text-white" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M4 4a2 2 0 00-2 2v4a2 2 0 002 2V6h10a2 2 0 00-2-2H4zm2 6a2 2 0 012-2h8a2 2 0 012 2v4a2 2 0 01-2 2H8a2 2 0 01-2-2v-4zm6 4a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd" />
                            </svg>
                        </div>
                    </div>
                    <div class="ml-5 w-0 flex-1">
                        <dl>
                            <dt class="text-sm font-medium text-green-100 truncate">Total Revenue</dt>
                            <dd class="text-2xl font-bold text-white">GHS {{ number_format($totalRevenue, 2) }}</dd>
                            <dd class="text-sm text-green-100 mt-1">All-time processed</dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>

        <!-- Active Vendors Card -->
        <div class="bg-gradient-to-br from-brand-deep-blue to-brand-bright-blue rounded-xl shadow-lg overflow-hidden transform hover:scale-105 transition-transform duration-200">
            <div class="px-6 py-5">
                <div class="flex items-center">
                    <div class="shrink-0">
                        <div class="w-14 h-14 bg-white/20 backdrop-blur-sm rounded-xl flex items-center justify-center">
                            <svg class="w-7 h-7 text-white" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v3h8v-3z" />
                            </svg>
                        </div>
                    </div>
                    <div class="ml-5 w-0 flex-1">
                        <dl>
                            <dt class="text-sm font-medium text-blue-100 truncate">Active Vendors</dt>
                            <dd class="text-2xl font-bold text-white">{{ $activeVendors }}</dd>
                            <dd class="text-sm text-blue-100 mt-1">Approved vendors</dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>

        <!-- Transactions Today Card -->
        <div class="bg-gradient-to-br from-brand-bright-blue to-blue-500 rounded-xl shadow-lg overflow-hidden transform hover:scale-105 transition-transform duration-200">
            <div class="px-6 py-5">
                <div class="flex items-center">
                    <div class="shrink-0">
                        <div class="w-14 h-14 bg-white/20 backdrop-blur-sm rounded-xl flex items-center justify-center">
                            <svg class="w-7 h-7 text-white" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                            </svg>
                        </div>
                    </div>
                    <div class="ml-5 w-0 flex-1">
                        <dl>
                            <dt class="text-sm font-medium text-blue-100 truncate">Transactions Today</dt>
                            <dd class="text-2xl font-bold text-white">{{ $transactionsToday }}</dd>
                            <dd class="text-sm text-blue-100 mt-1">Processed today</dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>

        <!-- Orders Today Card -->
        <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl shadow-lg overflow-hidden transform hover:scale-105 transition-transform duration-200">
            <div class="px-6 py-5">
                <div class="flex items-center">
                    <div class="shrink-0">
                        <div class="w-14 h-14 bg-white/20 backdrop-blur-sm rounded-xl flex items-center justify-center">
                            <svg class="w-7 h-7 text-white" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 2a4 4 0 00-4 4v1H5a1 1 0 00-.994.89l-1 9A1 1 0 004 18h12a1 1 0 00.994-1.11l-1-9A1 1 0 0015 7h-1V6a4 4 0 00-4-4zm2 5V6a2 2 0 10-4 0v1h4zm-6 3a1 1 0 112 0 1 1 0 01-2 0zm7-1a1 1 0 100 2 1 1 0 000-2z" clip-rule="evenodd" />
                            </svg>
                        </div>
                    </div>
                    <div class="ml-5 w-0 flex-1">
                        <dl>
                            <dt class="text-sm font-medium text-purple-100 truncate">Orders Today</dt>
                            <dd class="text-2xl font-bold text-white">{{ $ordersToday }}</dd>
                            <dd class="text-sm text-purple-100 mt-1">Submitted today</dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>

        <!-- Total Vendor Balances Card -->
        <div class="bg-gradient-to-br from-amber-500 to-orange-500 rounded-xl shadow-lg overflow-hidden transform hover:scale-105 transition-transform duration-200">
            <div class="px-6 py-5">
                <div class="flex items-center">
                    <div class="shrink-0">
                        <div class="w-14 h-14 bg-white/20 backdrop-blur-sm rounded-xl flex items-center justify-center">
                            <svg class="w-7 h-7 text-white" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 2a1 1 0 011 1v1.05a5.002 5.002 0 013.9 3.9H16a1 1 0 110 2h-1.1a5.002 5.002 0 01-3.9 3.9V15a1 1 0 11-2 0v-1.1a5.002 5.002 0 01-3.9-3.9H4a1 1 0 110-2h1.1a5.002 5.002 0 013.9-3.9V3a1 1 0 011-1zm0 4a3 3 0 100 6 3 3 0 000-6z" clip-rule="evenodd" />
                            </svg>
                        </div>
                    </div>
                    <div class="ml-5 w-0 flex-1">
                        <dl>
                            <dt class="text-sm font-medium text-amber-100 truncate">Total Withdrawable Balances</dt>
                            <dd class="text-2xl font-bold text-white">GHS {{ number_format($totalWithdrawableBalances, 2) }}</dd>
                            <dd class="text-sm text-amber-100 mt-1">Combined withdrawable balances</dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if ($pendingWithdrawals > 0)
        <div class="mb-8">
            <div class="rounded-xl border border-yellow-200 bg-yellow-50 px-5 py-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm font-semibold text-yellow-800">{{ $pendingWithdrawals }} withdrawal request{{ $pendingWithdrawals === 1 ? '' : 's' }} pending review.</p>
                    <p class="text-xs text-yellow-700">Approve or reject vendor payout requests to keep balances accurate.</p>
                </div>
                <x-button href="{{ route('admin.withdrawals.index') }}" variant="outline" size="sm" class="self-start sm:self-auto">
                    Review now
                </x-button>
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2">
            <x-card>
                <div class="px-4 py-5 sm:p-6">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between mb-4">
                        <h3 class="text-lg leading-6 font-medium text-gray-900">Recent Vendor Applications</h3>
                        <x-button href="{{ route('admin.vendors.index') }}" variant="outline" size="sm" class="w-full sm:w-auto justify-center">
                            View All
                        </x-button>
                    </div>

                    <x-table :headers="['Vendor', 'Business', 'Applied', 'Status', 'Actions']">
                        @forelse ($pendingVendors as $vendor)
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $vendor->name }}</td>
                                <td class="px-6 py-4 text-sm text-gray-900">{{ $vendor->business_name ?? 'N/A' }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $vendor->created_at?->diffForHumans() }}</td>
                                <td class="px-6 py-4 text-sm">
                                    <x-badge variant="pending">Pending Approval</x-badge>
                                </td>
                                <td class="px-6 py-4 text-sm">
                                    <div class="flex flex-wrap gap-2">
                                        <form method="POST" action="{{ route('admin.vendors.approve', $vendor) }}">
                                            @csrf
                                            <button type="submit" class="px-3 py-1.5 rounded-lg bg-green-600 text-white text-xs font-semibold">
                                                Approve
                                            </button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.vendors.reject', $vendor) }}">
                                            @csrf
                                            <button type="submit" class="px-3 py-1.5 rounded-lg bg-red-50 text-red-600 text-xs font-semibold">
                                                Reject
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-4 text-sm text-gray-500 text-center">No pending vendor applications.</td>
                            </tr>
                        @endforelse
                    </x-table>
                </div>
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card>
                <div class="px-4 py-5 sm:p-6">
                    <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4">System Status</h3>

                    <div class="space-y-4">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <div class="w-3 h-3 bg-brand-green rounded-full mr-3"></div>
                                <span class="text-sm font-medium">API Services</span>
                            </div>
                            <span class="text-sm text-gray-500">Operational</span>
                        </div>

                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <div class="w-3 h-3 bg-brand-green rounded-full mr-3"></div>
                                <span class="text-sm font-medium">Payment Gateway</span>
                            </div>
                            <span class="text-sm text-gray-500">Operational</span>
                        </div>

                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <div class="w-3 h-3 bg-yellow-500 rounded-full mr-3"></div>
                                <span class="text-sm font-medium">SMS Service</span>
                            </div>
                            <span class="text-sm text-gray-500">Degraded</span>
                        </div>

                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <div class="w-3 h-3 bg-brand-green rounded-full mr-3"></div>
                                <span class="text-sm font-medium">Database</span>
                            </div>
                            <span class="text-sm text-gray-500">Operational</span>
                        </div>
                    </div>

                    <div class="mt-6 pt-4 border-t border-gray-200">
                        <x-button href="#" variant="outline" size="sm" class="w-full justify-center">
                            View Detailed Status
                        </x-button>
                    </div>
                </div>
            </x-card>

            <x-card>
                <div class="px-4 py-5 sm:p-6">
                    <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4">Quick Actions</h3>
                    <div class="space-y-3">
                        <form method="POST" action="{{ route('admin.queue.run') }}">
                            @csrf
                            <x-button type="submit" variant="primary" class="w-full justify-center">
                                Run Queue Now
                            </x-button>
                        </form>

                        <div class="text-xs text-gray-500">
                            <div>Last requested: {{ $manualQueueLastRequestedAt ? \Carbon\Carbon::parse($manualQueueLastRequestedAt)->format('M d, Y H:i') : '—' }}</div>
                            <div>Last finished: {{ $manualQueueLastFinishedAt ? \Carbon\Carbon::parse($manualQueueLastFinishedAt)->format('M d, Y H:i') : '—' }}</div>
                        </div>

                        <x-button href="{{ route('admin.vendors.index') }}" variant="primary" class="w-full justify-center">
                            Manage Vendors
                        </x-button>

                        <x-button href="{{ route('admin.withdrawals.index') }}" variant="outline" class="w-full justify-center">
                            Review Withdrawals
                        </x-button>

                        <x-button href="#" variant="outline" class="w-full justify-center">
                            Generate Report
                        </x-button>
                    </div>
                </div>
            </x-card>
        </div>
    </div>

    <!-- Vendor Tier Section -->
    @if($pendingTierPromotions > 0)
        <div class="mt-8">
            <div class="rounded-xl border border-blue-200 bg-blue-50 px-5 py-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm font-semibold text-blue-800">{{ $pendingTierPromotions }} vendor{{ $pendingTierPromotions === 1 ? '' : 's' }} eligible for tier promotion.</p>
                    <p class="text-xs text-blue-700">Review and approve pending tier upgrades.</p>
                </div>
                <x-button href="{{ route('admin.vendor-tier-promotions.index') }}" variant="outline" size="sm" class="self-start sm:self-auto">
                    Review Promotions
                </x-button>
            </div>
        </div>
    @endif

    <div class="mt-8">
        <x-card>
            <div class="px-4 py-5 sm:p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg leading-6 font-medium text-gray-900">Vendor Tier Distribution</h3>
                    <x-button href="{{ route('admin.vendor-tiers.index') }}" variant="outline" size="sm">
                        Manage Tiers
                    </x-button>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    @forelse($tierDistribution as $tier)
                        <div class="rounded-lg p-4 border
                            {{ $tier->priority === 0 ? 'bg-gray-50 border-gray-200' : ($tier->priority === 1 ? 'bg-blue-50 border-blue-200' : ($tier->priority === 2 ? 'bg-yellow-50 border-yellow-200' : 'bg-purple-50 border-purple-200')) }}">
                            <p class="text-sm font-semibold
                                {{ $tier->priority === 0 ? 'text-gray-700' : ($tier->priority === 1 ? 'text-blue-800' : ($tier->priority === 2 ? 'text-yellow-800' : 'text-purple-800')) }}">
                                {{ $tier->name }}
                            </p>
                            <p class="text-2xl font-bold mt-1
                                {{ $tier->priority === 0 ? 'text-gray-900' : ($tier->priority === 1 ? 'text-blue-900' : ($tier->priority === 2 ? 'text-yellow-900' : 'text-purple-900')) }}">
                                {{ $tier->vendors_count }}
                            </p>
                            <p class="text-xs mt-1
                                {{ $tier->priority === 0 ? 'text-gray-500' : ($tier->priority === 1 ? 'text-blue-600' : ($tier->priority === 2 ? 'text-yellow-600' : 'text-purple-600')) }}">
                                {{ $tier->discount_value > 0 ? $tier->discount_value . '% discount' : 'Base tier' }}
                            </p>
                        </div>
                    @empty
                        <div class="col-span-4 text-sm text-gray-400 text-center py-4">No tiers configured.</div>
                    @endforelse
                </div>
            </div>
        </x-card>
    </div>

    <!-- Results Checker Section -->
    <div class="mt-8">
        <x-card>
            <div class="px-4 py-5 sm:p-6">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h3 class="text-lg leading-6 font-medium text-gray-900">Results Checker Activity</h3>
                        <p class="mt-1 text-sm text-gray-500">Today's results checker orders and activity</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <!-- Orders Today -->
                    <div class="bg-gradient-to-br from-cyan-50 to-blue-50 rounded-lg p-4 border border-cyan-100">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Orders Today</p>
                                <p class="mt-2 text-2xl font-bold text-gray-900">{{ $resultCheckerOrdersToday }}</p>
                            </div>
                            <div class="w-12 h-12 bg-cyan-100 rounded-lg flex items-center justify-center">
                                <svg class="w-6 h-6 text-cyan-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M3 1a1 1 0 000 2h1.22l.305 1.222a.997.997 0 00.01.042l1.358 5.43-.893.892C3.74 11.846 4.632 14 6.414 14H15a1 1 0 000-2H6.414l1-1H14a1 1 0 00.894-.553l3-6A1 1 0 0017 6H6.28l-.31-1.243A1 1 0 005 4H3z" />
                                    <path d="M16 16a2 2 0 11-4 0 2 2 0 014 0z" />
                                    <path d="M4 16a2 2 0 11-4 0 2 2 0 014 0z" />
                                </svg>
                            </div>
                        </div>
                    </div>

                    <!-- Revenue Today -->
                    <div class="bg-gradient-to-br from-emerald-50 to-green-50 rounded-lg p-4 border border-emerald-100">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Revenue Today</p>
                                <p class="mt-2 text-2xl font-bold text-gray-900">GHS {{ number_format($resultCheckerRevenueToday, 2) }}</p>
                            </div>
                            <div class="w-12 h-12 bg-emerald-100 rounded-lg flex items-center justify-center">
                                <svg class="w-6 h-6 text-emerald-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M4 4a2 2 0 00-2 2v4a2 2 0 002 2V6h10a2 2 0 00-2-2H4zm2 6a2 2 0 012-2h8a2 2 0 012 2v4a2 2 0 01-2 2H8a2 2 0 01-2-2v-4zm6 4a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </div>
                    </div>

                    <!-- Completed Orders -->
                    <div class="bg-gradient-to-br from-violet-50 to-purple-50 rounded-lg p-4 border border-violet-100">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Completed Today</p>
                                <p class="mt-2 text-2xl font-bold text-gray-900">{{ $resultCheckerCompletedToday }}</p>
                            </div>
                            <div class="w-12 h-12 bg-violet-100 rounded-lg flex items-center justify-center">
                                <svg class="w-6 h-6 text-violet-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </div>
                    </div>

                    <!-- Pending Stock -->
                    <div class="bg-gradient-to-br from-orange-50 to-amber-50 rounded-lg p-4 border border-orange-100">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Pending Stock</p>
                                <p class="mt-2 text-2xl font-bold text-gray-900">{{ $resultCheckerPendingStock }}</p>
                            </div>
                            <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center">
                                <svg class="w-6 h-6 text-orange-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </x-card>
    </div>
</x-admin-layout>
@endsection