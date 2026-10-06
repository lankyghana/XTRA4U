@extends('layouts.admin')

@section('title', 'Admin Dashboard - XTRA4U')
@section('description', 'System administration and management portal')

@section('content')
@php
    // "Needs attention": only counts the controller already computes (or the support queue size).
    $attention = [
        ['label' => 'Vendor applications awaiting approval', 'count' => $pendingVendorCount, 'href' => route('admin.vendors.index', ['status' => 'pending']), 'tone' => 'warning'],
        ['label' => 'Withdrawals in processing', 'count' => $pendingWithdrawals, 'href' => route('admin.withdrawals.index', ['status' => \App\Models\VendorWithdrawal::STATUS_PROCESSING]), 'tone' => 'warning'],
        ['label' => 'Support conversations waiting for a reply', 'count' => $supportWaiting, 'href' => route('admin.support.index'), 'tone' => 'warning'],
        ['label' => 'Vendors eligible for a tier promotion', 'count' => $pendingTierPromotions, 'href' => route('admin.vendor-tier-promotions.index'), 'tone' => 'brand'],
        ['label' => 'Result checker orders waiting for stock', 'count' => $resultCheckerPendingStock, 'href' => route('admin.result-checkers.orders.index'), 'tone' => 'warning'],
    ];
    $open = collect($attention)->where('count', '>', 0)->count();
@endphp
<x-admin-layout title="Dashboard" subtitle="What needs your attention and how the platform is performing today." active="dashboard">
    <x-slot name="actions">
        <span class="text-xs text-gray-500">Updated {{ now()->format('M d, H:i') }}</span>
        <x-button href="{{ route('admin.payment-health.index') }}" variant="outline" size="sm">Payment health</x-button>
    </x-slot>

    <div class="space-y-6">
        {{-- Key numbers --}}
        <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-3 xl:grid-cols-5">
            <x-admin.stat label="Total Revenue" value="GHS {{ number_format($totalRevenue, 2) }}" hint="Platform commission, all-time" tone="success" />
            <x-admin.stat label="Active Vendors" value="{{ number_format($activeVendors) }}" hint="Approved vendors" tone="brand" href="{{ route('admin.vendors.index') }}" />
            <x-admin.stat label="Transactions Today" value="{{ number_format($transactionsToday) }}" hint="Processed today" href="{{ route('admin.transactions.index') }}" />
            <x-admin.stat label="Orders Today" value="{{ number_format($ordersToday) }}" hint="Submitted today" href="{{ route('admin.orders.index') }}" />
            <x-admin.stat label="Total Withdrawable Balances" value="GHS {{ number_format($totalWithdrawableBalances, 2) }}" hint="Combined vendor balances" tone="warning" href="{{ route('admin.withdrawals.index') }}" class="col-span-2 lg:col-span-1" />
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-2">
                {{-- Needs attention --}}
                <section class="rounded-xl border border-gray-200 bg-white shadow-sm" aria-labelledby="attention-heading">
                    <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3.5">
                        <h2 id="attention-heading" class="text-sm font-semibold text-gray-900">Needs attention</h2>
                        <x-admin.status :status="$open > 0 ? 'pending' : 'completed'" :label="$open > 0 ? $open.' open' : 'All clear'" />
                    </div>
                    <ul class="divide-y divide-gray-100">
                        @foreach ($attention as $item)
                            <li>
                                <a href="{{ $item['href'] }}" class="flex items-center justify-between gap-3 px-5 py-3 transition hover:bg-violet-50/40">
                                    <span class="text-sm {{ $item['count'] > 0 ? 'font-medium text-gray-900' : 'text-gray-500' }}">{{ $item['label'] }}</span>
                                    <span class="flex flex-shrink-0 items-center gap-2">
                                        @if ($item['count'] > 0)
                                            <x-admin.status :status="$item['tone'] === 'brand' ? 'processing' : 'pending'" :label="(string) $item['count']" />
                                        @else
                                            <span class="text-xs text-gray-400">None</span>
                                        @endif
                                        <svg class="h-4 w-4 text-gray-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>

                {{-- Recent vendor applications --}}
                <section aria-labelledby="applications-heading">
                    <div class="mb-3 flex items-center justify-between">
                        <h2 id="applications-heading" class="text-sm font-semibold text-gray-900">Recent vendor applications</h2>
                        <a href="{{ route('admin.vendors.index') }}" class="admin-link text-sm">View all</a>
                    </div>
                    <x-admin.table :headers="['Vendor', 'Business', 'Applied', 'Status', '']">
                        @forelse ($pendingVendors as $vendor)
                            <tr>
                                <td class="font-medium text-gray-900">{{ $vendor->name }}</td>
                                <td class="text-gray-700">{{ $vendor->business_name ?? 'N/A' }}</td>
                                <td class="whitespace-nowrap text-gray-500">{{ $vendor->created_at?->diffForHumans() }}</td>
                                <td><x-admin.status status="pending" label="Pending approval" /></td>
                                <td>
                                    <div class="flex justify-end gap-2">
                                        <form method="POST" action="{{ route('admin.vendors.approve', $vendor) }}">
                                            @csrf
                                            <x-button type="submit" variant="success" size="sm">Approve</x-button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.vendors.reject', $vendor) }}" onsubmit="return confirm('Reject this vendor application?')">
                                            @csrf
                                            <x-button type="submit" variant="secondary" size="sm" class="!text-red-700">Reject</x-button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><x-admin.empty title="No vendor applications are waiting" description="New sign-ups that need approval will appear here." /></td></tr>
                        @endforelse
                    </x-admin.table>
                </section>
            </div>

            <div class="space-y-6">
                {{-- Operations --}}
                <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm" aria-labelledby="ops-heading">
                    <h2 id="ops-heading" class="text-sm font-semibold text-gray-900">Operations</h2>
                    <p class="mt-1 text-xs text-gray-500">Process queued jobs (fulfillment, SMS, payouts) on demand.</p>
                    <form method="POST" action="{{ route('admin.queue.run') }}" class="mt-3">
                        @csrf
                        <x-button type="submit" variant="primary" class="w-full justify-center">Run queue now</x-button>
                    </form>
                    <dl class="mt-3 space-y-1 text-xs text-gray-500">
                        <div class="flex justify-between gap-2"><dt>Last requested</dt><dd class="text-gray-700">{{ $manualQueueLastRequestedAt ? \Carbon\Carbon::parse($manualQueueLastRequestedAt)->format('M d, Y H:i') : '—' }}</dd></div>
                        <div class="flex justify-between gap-2"><dt>Last finished</dt><dd class="text-gray-700">{{ $manualQueueLastFinishedAt ? \Carbon\Carbon::parse($manualQueueLastFinishedAt)->format('M d, Y H:i') : '—' }}</dd></div>
                    </dl>
                </section>

                {{-- Shortcuts --}}
                <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm" aria-labelledby="links-heading">
                    <h2 id="links-heading" class="text-sm font-semibold text-gray-900">Shortcuts</h2>
                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <x-button href="{{ route('admin.vendors.index') }}" variant="secondary" size="sm" class="justify-center">Vendors</x-button>
                        <x-button href="{{ route('admin.withdrawals.index') }}" variant="secondary" size="sm" class="justify-center">Withdrawals</x-button>
                        <x-button href="{{ route('admin.reports.index') }}" variant="secondary" size="sm" class="justify-center">Reports</x-button>
                        <x-button href="{{ route('admin.support.index') }}" variant="secondary" size="sm" class="justify-center">Support</x-button>
                    </div>
                </section>

                {{-- Vendor tiers --}}
                <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm" aria-labelledby="tiers-heading">
                    <div class="flex items-center justify-between">
                        <h2 id="tiers-heading" class="text-sm font-semibold text-gray-900">Vendor tiers</h2>
                        <a href="{{ route('admin.vendor-tiers.index') }}" class="admin-link text-sm">Manage</a>
                    </div>
                    <ul class="mt-3 divide-y divide-gray-100">
                        @forelse ($tierDistribution as $tier)
                            <li class="flex items-center justify-between py-2">
                                <div>
                                    <p class="text-sm font-medium text-gray-900">{{ $tier->name }}</p>
                                    <p class="text-xs text-gray-500">{{ $tier->discount_value > 0 ? $tier->discount_value . '% discount' : 'Base tier' }}</p>
                                </div>
                                <span class="text-lg font-semibold tabular-nums text-gray-900">{{ $tier->vendors_count }}</span>
                            </li>
                        @empty
                            <li><x-admin.empty title="No tiers configured" /></li>
                        @endforelse
                    </ul>
                </section>
            </div>
        </div>

        {{-- Result checker --}}
        <section aria-labelledby="rc-heading">
            <div class="mb-3 flex items-center justify-between">
                <div>
                    <h2 id="rc-heading" class="text-sm font-semibold text-gray-900">Result checker activity</h2>
                    <p class="text-xs text-gray-500">Today's result checker orders.</p>
                </div>
                <a href="{{ route('admin.result-checkers.dashboard') }}" class="admin-link text-sm">Open</a>
            </div>
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <x-admin.stat label="Orders Today" value="{{ $resultCheckerOrdersToday }}" />
                <x-admin.stat label="Revenue Today" value="GHS {{ number_format($resultCheckerRevenueToday, 2) }}" tone="success" />
                <x-admin.stat label="Completed Today" value="{{ $resultCheckerCompletedToday }}" tone="success" />
                <x-admin.stat label="Pending Stock" value="{{ $resultCheckerPendingStock }}" tone="{{ $resultCheckerPendingStock > 0 ? 'warning' : 'neutral' }}" />
            </div>
        </section>
    </div>
</x-admin-layout>
@endsection
