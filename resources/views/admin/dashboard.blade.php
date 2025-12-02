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

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <x-card variant="metric" padding="md">
            <div class="flex items-center">
                <div class="shrink-0">
                    <div class="w-12 h-12 bg-brand-green rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M4 4a2 2 0 00-2 2v4a2 2 0 002 2V6h10a2 2 0 00-2-2H4zm2 6a2 2 0 012-2h8a2 2 0 012 2v4a2 2 0 01-2 2H8a2 2 0 01-2-2v-4zm6 4a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd" />
                        </svg>
                    </div>
                </div>
                <div class="ml-5 w-0 flex-1">
                    <dl>
                        <dt class="text-sm font-medium text-gray-500 truncate">Total Revenue</dt>
                        <dd class="text-xl font-semibold text-gray-900">GHS {{ number_format($totalRevenue, 2) }}</dd>
                        <dd class="text-sm text-green-600">All-time processed</dd>
                    </dl>
                </div>
            </div>
        </x-card>

        <x-card variant="metric" padding="md">
            <div class="flex items-center">
                <div class="shrink-0">
                    <div class="w-12 h-12 bg-brand-deep-blue rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v3h8v-3z" />
                        </svg>
                    </div>
                </div>
                <div class="ml-5 w-0 flex-1">
                    <dl>
                        <dt class="text-sm font-medium text-gray-500 truncate">Active Vendors</dt>
                        <dd class="text-xl font-semibold text-gray-900">{{ $activeVendors }}</dd>
                        <dd class="text-sm text-green-600">Approved vendors</dd>
                    </dl>
                </div>
            </div>
        </x-card>

        <x-card variant="metric" padding="md">
            <div class="flex items-center">
                <div class="shrink-0">
                    <div class="w-12 h-12 bg-brand-bright-blue rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                        </svg>
                    </div>
                </div>
                <div class="ml-5 w-0 flex-1">
                    <dl>
                        <dt class="text-sm font-medium text-gray-500 truncate">Transactions Today</dt>
                        <dd class="text-xl font-semibold text-gray-900">{{ $transactionsToday }}</dd>
                        <dd class="text-sm text-green-600">Processed today</dd>
                    </dl>
                </div>
            </div>
        </x-card>

        <x-card variant="metric" padding="md">
            <div class="flex items-center">
                <div class="shrink-0">
                    <div class="w-12 h-12 bg-purple-500 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                        </svg>
                    </div>
                </div>
                <div class="ml-5 w-0 flex-1">
                    <dl>
                        <dt class="text-sm font-medium text-gray-500 truncate">Orders Today</dt>
                        <dd class="text-xl font-semibold text-gray-900">{{ $ordersToday }}</dd>
                        <dd class="text-sm text-green-600">Submitted today</dd>
                    </dl>
                </div>
            </div>
        </x-card>
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
</x-admin-layout>
@endsection