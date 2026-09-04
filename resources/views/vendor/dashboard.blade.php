@extends('layouts.vendor')

@section('title', 'Vendor Dashboard - XTRA4U')
@section('description', 'Manage your vendor account and track your performance')

@section('content')
<x-vendor-layout :vendor="$vendor" title="Dashboard" subtitle="Manage your vendor account and track your performance" active="dashboard">
    <x-slot name="actions">
        @if($vendor->vendor_code)
        <div x-data="{ copied: false }" class="shrink-0">
            <button
                @click="navigator.clipboard.writeText('{{ route('storefront.vendor', ['vendor' => $vendor->vendor_code]) }}'); copied = true; setTimeout(() => copied = false, 2000)"
                class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg shadow-sm transition-colors whitespace-nowrap"
                :class="copied ? 'bg-green-600 text-white' : 'bg-brand-violet text-white hover:bg-brand-violet-deep'"
            >
                <svg x-show="!copied" class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                </svg>
                <svg x-show="copied" x-cloak class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                <span x-text="copied ? 'Link Copied!' : 'Share Store Link'"></span>
            </button>
        </div>
        <x-button href="{{ route('vendor.quick-buy.show') }}" variant="secondary" class="whitespace-nowrap shrink-0">
            Quick Buy
        </x-button>
        @endif
    </x-slot>

    @if(!empty($deliveryStatus))
        @php
            $dsStyles = [
                'fast'   => ['label' => 'Fast delivery', 'bar' => 'bg-green-500', 'dot' => 'bg-green-500', 'badge' => 'bg-green-100 text-green-700', 'icon' => 'M13 10V3L4 14h7v7l9-11h-7z'],
                'normal' => ['label' => 'Delivery update', 'bar' => 'bg-blue-500', 'dot' => 'bg-blue-500', 'badge' => 'bg-blue-100 text-blue-700', 'icon' => 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
                'slow'   => ['label' => 'Slow delivery', 'bar' => 'bg-amber-500', 'dot' => 'bg-amber-500', 'badge' => 'bg-amber-100 text-amber-700', 'icon' => 'M12 8v4m0 4h.01M12 3a9 9 0 100 18 9 9 0 000-18z'],
            ];
            $ds = $dsStyles[$deliveryStatus['level']] ?? $dsStyles['normal'];
        @endphp
        <div
            x-data="{
                open: false,
                version: @js($deliveryStatus['version']),
                init() {
                    if (localStorage.getItem('deliveryNoticeDismissed') !== this.version) {
                        this.open = true;
                    }
                },
                dismiss() {
                    localStorage.setItem('deliveryNoticeDismissed', this.version);
                    this.open = false;
                }
            }"
            x-show="open"
            x-cloak
            @keydown.escape.window="dismiss()"
            class="fixed inset-0 z-50 flex items-center justify-center p-4"
        >
            <div x-show="open" x-transition.opacity class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" @click="dismiss()"></div>

            <div
                x-show="open"
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0 translate-y-4 sm:scale-95"
                x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl overflow-hidden"
                role="dialog"
                aria-modal="true"
            >
                <div class="h-1.5 w-full {{ $ds['bar'] }}"></div>
                <div class="p-6">
                    <div class="flex items-start gap-4">
                        <div class="shrink-0 w-11 h-11 rounded-full {{ $ds['badge'] }} flex items-center justify-center">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $ds['icon'] }}" />
                            </svg>
                        </div>
                        <div class="min-w-0">
                            <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-2.5 py-1 rounded-full {{ $ds['badge'] }}">
                                <span class="w-1.5 h-1.5 rounded-full {{ $ds['dot'] }}"></span>
                                {{ $ds['label'] }}
                            </span>
                            <p class="mt-3 text-sm leading-relaxed text-gray-700 whitespace-pre-line">{{ $deliveryStatus['message'] }}</p>
                        </div>
                    </div>
                    <button
                        @click="dismiss()"
                        class="mt-6 w-full rounded-xl bg-brand-violet hover:bg-brand-violet-deep text-white text-sm font-semibold py-2.5 transition-colors"
                    >
                        Got it — Continue
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Sales Overview -->
    <div x-data="salesFilter()" class="mb-8">
        <!-- Compact section header: no standalone card, just a heading row -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">Sales Overview</h2>
                <p class="text-sm text-gray-500">Track your revenue and earnings</p>
            </div>
            <div class="flex items-center gap-1 p-1 bg-gray-100 rounded-lg self-start sm:self-auto">
                <button @click="setFilter('today')" :class="activeFilter === 'today' ? 'bg-white text-brand-violet shadow-sm' : 'text-gray-600 hover:text-gray-900'" class="px-3 py-1.5 text-xs font-semibold rounded-md transition-all duration-200">
                    Today
                </button>
                <button @click="setFilter('yesterday')" :class="activeFilter === 'yesterday' ? 'bg-white text-brand-violet shadow-sm' : 'text-gray-600 hover:text-gray-900'" class="px-3 py-1.5 text-xs font-semibold rounded-md transition-all duration-200">
                    Yesterday
                </button>
                <button @click="setFilter('this_week')" :class="activeFilter === 'this_week' ? 'bg-white text-brand-violet shadow-sm' : 'text-gray-600 hover:text-gray-900'" class="px-3 py-1.5 text-xs font-semibold rounded-md transition-all duration-200 hidden sm:block">
                    Week
                </button>
                <button @click="setFilter('this_month')" :class="activeFilter === 'this_month' ? 'bg-white text-brand-violet shadow-sm' : 'text-gray-600 hover:text-gray-900'" class="px-3 py-1.5 text-xs font-semibold rounded-md transition-all duration-200">
                    Month
                </button>
                <button @click="setFilter('all_time')" :class="activeFilter === 'all_time' ? 'bg-white text-brand-violet shadow-sm' : 'text-gray-600 hover:text-gray-900'" class="px-3 py-1.5 text-xs font-semibold rounded-md transition-all duration-200">
                    All
                </button>
            </div>
        </div>

        <!-- Metric Cards: consistent label / value / helper pattern, no per-card period badges
             (the period pills above already communicate the selected period) -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <!-- Total Sales -->
            <div class="bg-white rounded-xl border border-gray-200 p-5 flex flex-col justify-between min-h-[128px]">
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Total Sales</p>
                <div class="mt-3 flex items-baseline gap-1">
                    <span x-show="loading" class="inline-block w-5 h-5 border-2 border-gray-200 border-t-brand-violet rounded-full animate-spin"></span>
                    <template x-if="!loading">
                        <span>
                            <span class="text-gray-400 text-sm font-medium">GHS</span>
                            <span class="text-2xl font-bold text-gray-900" x-text="sales"></span>
                        </span>
                    </template>
                </div>
                <p class="mt-1.5 text-[11px] text-gray-400">Sales during selected period</p>
            </div>

            <!-- Net Earnings -->
            <div class="bg-white rounded-xl border border-gray-200 p-5 flex flex-col justify-between min-h-[128px]">
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Total Earnings (Net)</p>
                <div class="mt-3 flex items-baseline gap-1">
                    <span x-show="loading" class="inline-block w-5 h-5 border-2 border-gray-200 border-t-brand-violet rounded-full animate-spin"></span>
                    <template x-if="!loading">
                        <span>
                            <span class="text-gray-400 text-sm font-medium">GHS</span>
                            <span class="text-2xl font-bold text-gray-900" x-text="earnings"></span>
                        </span>
                    </template>
                </div>
                <p class="mt-1.5 text-[11px] text-gray-400">After applicable fees</p>
            </div>

            <!-- Available Balance: not period-filtered, always current. Its own action
                 replaces the removed dashboard withdrawal form. -->
            <div class="bg-white rounded-xl border border-gray-200 p-5 flex flex-col justify-between min-h-[128px]">
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Available Balance</p>
                <div class="mt-3 flex items-baseline gap-1">
                    <span class="text-gray-400 text-sm font-medium">GHS</span>
                    <span class="text-2xl font-bold text-gray-900">{{ number_format($withdrawableBalance, 2) }}</span>
                </div>
                <a href="{{ route('vendor.withdrawals.index') }}" class="mt-1.5 inline-flex items-center gap-1 text-[11px] font-semibold text-brand-violet hover:text-brand-violet-deep">
                    Withdraw Funds
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3" />
                    </svg>
                </a>
            </div>
        </div>
    </div>

    <!-- Operational section: Recent Orders (~70%) + Quick Actions (~30%) -->
    <div class="grid grid-cols-1 gap-6 mb-8 lg:grid-cols-10">
        <div class="lg:col-span-7 bg-white rounded-xl border border-gray-200">
            <div class="px-6 py-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-base font-semibold text-gray-900">Recent Orders</h3>
                    <x-button href="{{ route('vendor.orders.index') }}" variant="outline" size="sm">
                        View All &rarr;
                    </x-button>
                </div>

                @if ($orders->isEmpty())
                    <!-- Compact empty state -->
                    <div class="flex flex-col items-center justify-center text-center py-10 px-4 border border-dashed border-gray-200 rounded-lg">
                        <svg class="w-8 h-8 text-gray-300 mb-2" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                        </svg>
                        <p class="text-sm font-medium text-gray-700">No orders yet</p>
                        <p class="text-xs text-gray-400 mt-0.5">New customer orders will appear here.</p>
                        @if ($storeLink)
                            <x-button href="{{ $storeLink }}" variant="outline" size="sm" class="mt-3">
                                View Storefront
                            </x-button>
                        @endif
                    </div>
                @else
                    @php
                        $recentOrders = $orders->take(5)->map(function ($order) {
                            $isAfaOrder = $order instanceof \App\Models\AfaRegistration;
                            $rawStatus = strtolower((string) $order->status);

                            return (object) [
                                'reference' => $isAfaOrder ? ($order->reference ?: ('AFA-' . $order->id)) : ('#' . $order->id),
                                'service' => $isAfaOrder ? 'AFA Registration' : $order->display_product_label,
                                'amount' => $isAfaOrder ? (float) $order->amount : (float) $order->amount_paid,
                                'status_label' => $isAfaOrder ? ucfirst((string) $order->status) : (string) $order->status,
                                'status_variant' => in_array($rawStatus, ['completed', 'approved'], true)
                                    ? 'completed'
                                    : (in_array($rawStatus, ['rejected', 'cancelled', 'failed'], true) ? 'warning' : 'pending'),
                                'date' => $order->created_at,
                                // Existing routes only: AFA rows link to their own detail page;
                                // product orders reuse the Orders page's existing search (`q`).
                                'action_url' => $isAfaOrder
                                    ? route('vendor.afa.show', $order)
                                    : route('vendor.orders.index', ['q' => $order->id]),
                            ];
                        });
                    @endphp

                    <!-- Desktop table -->
                    <div class="hidden sm:block overflow-hidden rounded-lg border border-gray-200">
                        <x-table :headers="['Order', 'Service', 'Amount', 'Status', 'Date', '']">
                            @foreach ($recentOrders as $row)
                                <tr class="hover:bg-gray-50 transition-colors duration-150">
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $row->reference }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-700 max-w-[180px] truncate">{{ $row->service }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-900">GHS {{ number_format($row->amount, 2) }}</td>
                                    <td class="px-6 py-4 text-sm">
                                        <x-badge :variant="$row->status_variant">{{ $row->status_label }}</x-badge>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500">{{ $row->date?->format('M d, Y') }}</td>
                                    <td class="px-6 py-4 text-sm text-right">
                                        <a href="{{ $row->action_url }}" class="text-brand-violet hover:text-brand-violet-deep font-medium">View</a>
                                    </td>
                                </tr>
                            @endforeach
                        </x-table>
                    </div>

                    <!-- Mobile stacked cards -->
                    <div class="sm:hidden space-y-3">
                        @foreach ($recentOrders as $row)
                            <div class="border border-gray-200 rounded-lg p-4">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-sm font-semibold text-gray-900">{{ $row->reference }}</span>
                                    <x-badge :variant="$row->status_variant">{{ $row->status_label }}</x-badge>
                                </div>
                                <p class="text-sm text-gray-700 mt-1 truncate">{{ $row->service }}</p>
                                <p class="text-sm font-semibold text-gray-900 mt-0.5">GHS {{ number_format($row->amount, 2) }}</p>
                                <div class="flex items-center justify-between mt-2">
                                    <span class="text-xs text-gray-400">{{ $row->date?->format('M d, Y') }}</span>
                                    <a href="{{ $row->action_url }}" class="text-xs font-semibold text-brand-violet hover:text-brand-violet-deep">View &rarr;</a>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <div class="lg:col-span-3 bg-white rounded-xl border border-gray-200">
            <div class="px-6 py-6">
                <h3 class="text-base font-semibold text-gray-900 mb-4">Quick Actions</h3>
                <div class="space-y-2">
                    <x-button href="{{ route('vendor.products.create') }}" variant="primary" class="w-full justify-center">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                        </svg>
                        Add Product
                    </x-button>

                    <x-button href="{{ route('vendor.withdrawals.index') }}" variant="outline" class="w-full justify-center">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                        Withdraw Funds
                    </x-button>

                    @if ($storeLink)
                        <x-button href="{{ $storeLink }}" variant="outline" class="w-full justify-center">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                            </svg>
                            View Storefront
                        </x-button>
                    @endif

                    <x-button href="{{ route('vendor.quick-buy.show') }}" variant="outline" class="w-full justify-center">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                        Quick Buy
                    </x-button>
                </div>
            </div>
        </div>
    </div>

    <!-- Results Checker Section -->
    <div class="mt-6">
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 bg-brand-violet-soft rounded-lg flex items-center justify-center">
                        <svg class="w-5 h-5 text-brand-violet" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M7 3a1 1 0 000 2h6a1 1 0 000-2H7zM4 7a1 1 0 011-1h10a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1V7z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">Results Checker Activity</h3>
                        <p class="text-xs text-gray-500">Today's results checker orders and performance</p>
                    </div>
                </div>
            </div>
            <div class="px-6 py-6">
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Orders Today</p>
                        <p class="mt-2 text-2xl font-bold text-gray-900">{{ $resultCheckerOrdersToday }}</p>
                    </div>

                    <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Revenue Today</p>
                        <p class="mt-2 text-2xl font-bold text-gray-900">GHS {{ number_format($resultCheckerRevenueToday, 2) }}</p>
                    </div>

                    <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Completed</p>
                        <p class="mt-2 text-2xl font-bold text-gray-900">{{ $resultCheckerCompletedToday }}</p>
                    </div>

                    <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Pending Stock</p>
                        <p class="mt-2 text-2xl font-bold text-gray-900">{{ $resultCheckerPendingStock }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-vendor-layout>
@endsection

@push('scripts')
<script>
function salesFilter() {
    return {
        activeFilter: '{{ $filter ?? "today" }}',
        sales: '{{ number_format($totalSales, 2) }}',
        earnings: '{{ number_format($totalEarnings, 2) }}',
        loading: false,

        get filterLabel() {
            const labels = {
                'today': 'Today',
                'yesterday': 'Yesterday',
                'this_week': 'This Week',
                'this_month': 'This Month',
                'all_time': 'All Time'
            };
            return labels[this.activeFilter] || 'Today';
        },

        async setFilter(filter) {
            if (this.activeFilter === filter) return;

            this.activeFilter = filter;
            this.loading = true;

            try {
                const response = await fetch(`{{ route('vendor.dashboard.sales-stats') }}?filter=${filter}`, {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                if (response.ok) {
                    const data = await response.json();
                    this.sales = data.sales;
                    this.earnings = data.earnings;
                }
            } catch (error) {
                console.error('Failed to fetch stats:', error);
            } finally {
                this.loading = false;
            }
        }
    }
}
</script>
@endpush
