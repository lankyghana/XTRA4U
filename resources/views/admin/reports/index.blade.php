@extends('layouts.admin')

@section('title', 'Reports - Admin Portal')

@section('content')
<x-admin-layout title="Reports" subtitle="Sales, revenue, and wallet topup summary for the selected period" active="reports">
    <div class="space-y-8">

        {{-- Filters --}}
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100">
                <h2 class="text-sm font-semibold text-gray-900">Filters</h2>
                <p class="text-xs text-gray-500 mt-0.5">Defaults to the current month. Leave Vendor ID blank to see platform-wide data.</p>
            </div>
            <div class="p-5">
                <form method="GET" action="{{ route('admin.reports.index') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-4 items-end">
                    <div class="lg:col-span-4">
                        <label class="block text-xs font-medium text-gray-600 mb-1" for="rpt_date_from">Date from</label>
                        <input id="rpt_date_from" type="date" name="date_from" value="{{ $filters['date_from'] }}"
                            class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-brand-deep-blue focus:border-brand-deep-blue" />
                    </div>
                    <div class="lg:col-span-4">
                        <label class="block text-xs font-medium text-gray-600 mb-1" for="rpt_date_to">Date to</label>
                        <input id="rpt_date_to" type="date" name="date_to" value="{{ $filters['date_to'] }}"
                            class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-brand-deep-blue focus:border-brand-deep-blue" />
                    </div>
                    <div class="lg:col-span-2">
                        <label class="block text-xs font-medium text-gray-600 mb-1" for="rpt_vendor_id">Vendor ID</label>
                        <input id="rpt_vendor_id" type="number" name="vendor_id" value="{{ $filters['vendor_id'] ?? '' }}" placeholder="All"
                            class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-brand-deep-blue focus:border-brand-deep-blue" />
                    </div>
                    <div class="lg:col-span-2 flex gap-2">
                        <x-button type="submit" variant="primary" class="flex-1 justify-center">Apply</x-button>
                        <x-button href="{{ route('admin.reports.index') }}" variant="outline" class="flex-1 justify-center">Reset</x-button>
                    </div>
                </form>
            </div>
        </div>

        {{-- ── SALES SUMMARY ─────────────────────────────────────────────── --}}
        <div>
            <h3 class="text-base font-semibold text-gray-900 mb-4">Sales Summary</h3>

            {{-- Stat cards --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                @php
                    $cards = [
                        ['label' => 'Total Orders',       'value' => number_format($totals->order_count)],
                        ['label' => 'Total Revenue',      'value' => 'GHS ' . number_format($totals->total_revenue, 2)],
                        ['label' => 'Platform Commission','value' => 'GHS ' . number_format($totals->platform_commission, 2)],
                        ['label' => 'Vendor Earnings',    'value' => 'GHS ' . number_format($totals->owner_earning + $totals->reseller_earning, 2)],
                    ];
                @endphp
                @foreach($cards as $card)
                    <div class="bg-white rounded-xl border border-gray-200 px-5 py-4">
                        <div class="text-xs font-medium text-gray-500 uppercase tracking-wide">{{ $card['label'] }}</div>
                        <div class="mt-1 text-xl font-bold text-gray-900 truncate">{{ $card['value'] }}</div>
                    </div>
                @endforeach
            </div>

            {{-- Daily sales --}}
            <div class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-6">
                <div class="px-5 py-3 border-b border-gray-100">
                    <h4 class="text-sm font-semibold text-gray-800">Orders by Day</h4>
                </div>
                <x-table :headers="['Date', 'Orders', 'Revenue (GHS)']">
                    @forelse($salesByDay as $row)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3 text-sm text-gray-900 font-medium whitespace-nowrap">
                                {{ \Carbon\Carbon::parse($row->date)->format('D, M j Y') }}
                            </td>
                            <td class="px-6 py-3 text-sm text-gray-700">{{ number_format($row->order_count) }}</td>
                            <td class="px-6 py-3 text-sm text-gray-700 font-mono">{{ number_format($row->revenue, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-6 py-8 text-center text-sm text-gray-500">No paid orders in this period.</td></tr>
                    @endforelse
                </x-table>
            </div>

            {{-- Top services --}}
            <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100">
                    <h4 class="text-sm font-semibold text-gray-800">Top Services by Revenue</h4>
                </div>
                <x-table :headers="['Service', 'Orders', 'Revenue (GHS)']">
                    @forelse($topServices as $row)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3 text-sm text-gray-900">
                                <span class="font-mono text-xs bg-gray-100 px-2 py-1 rounded">{{ $row->service_purchased }}</span>
                            </td>
                            <td class="px-6 py-3 text-sm text-gray-700">{{ number_format($row->order_count) }}</td>
                            <td class="px-6 py-3 text-sm text-gray-700 font-mono">{{ number_format($row->revenue, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-6 py-8 text-center text-sm text-gray-500">No data.</td></tr>
                    @endforelse
                </x-table>
            </div>
        </div>

        {{-- ── REVENUE BREAKDOWN ─────────────────────────────────────────── --}}
        <div>
            <h3 class="text-base font-semibold text-gray-900 mb-4">Revenue Breakdown</h3>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                {{-- Earnings split --}}
                <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100">
                        <h4 class="text-sm font-semibold text-gray-800">Earnings Split</h4>
                    </div>
                    <div class="divide-y divide-gray-100">
                        @php
                            $splits = [
                                ['label' => 'Total Revenue',      'amount' => $totals->total_revenue],
                                ['label' => 'Platform (2%)',      'amount' => $totals->platform_commission],
                                ['label' => 'Owner Vendor',       'amount' => $totals->owner_earning],
                                ['label' => 'Reseller Vendor',    'amount' => $totals->reseller_earning],
                            ];
                        @endphp
                        @foreach($splits as $split)
                            <div class="px-5 py-3 flex items-center justify-between">
                                <span class="text-sm text-gray-600">{{ $split['label'] }}</span>
                                <span class="text-sm font-mono font-semibold text-gray-900">GHS {{ number_format($split['amount'], 2) }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- By payment source --}}
                <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100">
                        <h4 class="text-sm font-semibold text-gray-800">By Payment Source</h4>
                    </div>
                    <x-table :headers="['Source', 'Orders', 'Revenue (GHS)']">
                        @forelse($byPaymentSource as $row)
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-3 text-sm text-gray-900 capitalize">{{ $row->payment_source ?: 'unknown' }}</td>
                                <td class="px-6 py-3 text-sm text-gray-700">{{ number_format($row->order_count) }}</td>
                                <td class="px-6 py-3 text-sm text-gray-700 font-mono">{{ number_format($row->revenue, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-6 py-8 text-center text-sm text-gray-500">No data.</td></tr>
                        @endforelse
                    </x-table>
                </div>
            </div>

            {{-- Top vendors --}}
            <div class="bg-white rounded-xl border border-gray-200 overflow-hidden mt-6">
                <div class="px-5 py-3 border-b border-gray-100">
                    <h4 class="text-sm font-semibold text-gray-800">Top Vendors by Revenue</h4>
                </div>
                <x-table :headers="['Vendor', 'Orders', 'Revenue (GHS)']">
                    @forelse($topVendors as $row)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3 text-sm text-gray-900">
                                <div class="font-medium">{{ $row->vendor_name }}</div>
                                <div class="text-xs text-gray-500">#{{ $row->vendor_id }} • {{ $row->vendor_code }}</div>
                            </td>
                            <td class="px-6 py-3 text-sm text-gray-700">{{ number_format($row->order_count) }}</td>
                            <td class="px-6 py-3 text-sm text-gray-700 font-mono">{{ number_format($row->revenue, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-6 py-8 text-center text-sm text-gray-500">No data.</td></tr>
                    @endforelse
                </x-table>
            </div>
        </div>

        {{-- ── WALLET TOPUP HISTORY ──────────────────────────────────────── --}}
        <div>
            <h3 class="text-base font-semibold text-gray-900 mb-4">Wallet Topup History</h3>

            {{-- Summary by status --}}
            <div class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-6">
                <div class="px-5 py-3 border-b border-gray-100">
                    <h4 class="text-sm font-semibold text-gray-800">Topups by Status</h4>
                </div>
                <x-table :headers="['Status', 'Count', 'Total Amount (GHS)', 'Total Consumed (GHS)']">
                    @forelse($topupsByStatus as $row)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3 text-sm">
                                <span @class([
                                    'inline-flex items-center px-2 py-0.5 rounded text-xs font-medium',
                                    'bg-green-100 text-green-800' => $row->status === 'completed',
                                    'bg-yellow-100 text-yellow-800' => in_array($row->status, ['initiated', 'pending']),
                                    'bg-red-100 text-red-800' => in_array($row->status, ['failed', 'expired']),
                                    'bg-gray-100 text-gray-700' => !in_array($row->status, ['completed', 'initiated', 'pending', 'failed', 'expired']),
                                ])>{{ $row->status }}</span>
                            </td>
                            <td class="px-6 py-3 text-sm text-gray-700">{{ number_format($row->count) }}</td>
                            <td class="px-6 py-3 text-sm text-gray-700 font-mono">{{ number_format($row->total_amount, 2) }}</td>
                            <td class="px-6 py-3 text-sm text-gray-700 font-mono">{{ number_format($row->total_consumed, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-6 py-8 text-center text-sm text-gray-500">No topups in this period.</td></tr>
                    @endforelse
                </x-table>
            </div>

            {{-- Recent topups --}}
            <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100">
                    <h4 class="text-sm font-semibold text-gray-800">Recent Topups <span class="text-xs font-normal text-gray-400">(latest 25)</span></h4>
                </div>
                <x-table :headers="['Date', 'Vendor', 'Reference', 'Amount (GHS)', 'Consumed (GHS)', 'Status']">
                    @forelse($recentTopups as $topup)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3 text-sm text-gray-600 whitespace-nowrap">
                                {{ optional($topup->created_at)->format('M j, Y') }}
                                <div class="text-xs text-gray-400">{{ optional($topup->created_at)->format('H:i') }}</div>
                            </td>
                            <td class="px-6 py-3 text-sm text-gray-700">
                                @if($topup->vendor)
                                    <div class="font-medium">{{ $topup->vendor->name }}</div>
                                    <div class="text-xs text-gray-500">{{ $topup->vendor->vendor_code }}</div>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-6 py-3 text-sm font-mono text-gray-600 text-xs">{{ $topup->reference }}</td>
                            <td class="px-6 py-3 text-sm text-gray-700 font-mono">{{ number_format($topup->amount, 2) }}</td>
                            <td class="px-6 py-3 text-sm text-gray-700 font-mono">{{ number_format($topup->consumed, 2) }}</td>
                            <td class="px-6 py-3 text-sm">
                                <span @class([
                                    'inline-flex items-center px-2 py-0.5 rounded text-xs font-medium',
                                    'bg-green-100 text-green-800' => $topup->status === 'completed',
                                    'bg-yellow-100 text-yellow-800' => in_array($topup->status, ['initiated', 'pending']),
                                    'bg-red-100 text-red-800' => in_array($topup->status, ['failed', 'expired']),
                                    'bg-gray-100 text-gray-700' => !in_array($topup->status, ['completed', 'initiated', 'pending', 'failed', 'expired']),
                                ])>{{ $topup->status }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-6 py-8 text-center text-sm text-gray-500">No topups in this period.</td></tr>
                    @endforelse
                </x-table>
            </div>
        </div>

    </div>
</x-admin-layout>
@endsection
