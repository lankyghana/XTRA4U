@extends('layouts.vendor')

@section('title', 'Analytics - XTRA4U')
@section('description', 'Track your performance metrics and business insights')

@section('content')
<x-vendor-layout :vendor="$vendor" title="Analytics" subtitle="Track your performance metrics and business insights" active="analytics">
    <div class="space-y-6">
        <!-- Overview Stats -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <!-- Total Orders -->
            <div class="bg-gradient-to-br from-brand-deep-blue to-brand-bright-blue rounded-xl shadow-lg overflow-hidden transform hover:scale-105 transition-transform duration-200">
                <div class="px-6 py-5">
                    <div class="flex items-center">
                        <div class="shrink-0">
                            <div class="w-12 h-12 bg-white/20 backdrop-blur-sm rounded-xl flex items-center justify-center">
                                <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M3 1a1 1 0 000 2h1.22l.305 1.222a.997.997 0 00.01.042l1.358 5.43-.893.892C3.74 11.846 4.632 14 6.414 14H15a1 1 0 000-2H6.414l1-1H14a1 1 0 00.894-.553l3-6A1 1 0 0017 3H6.28l-.31-1.243A1 1 0 005 1H3zM16 16.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM6.5 18a1.5 1.5 0 100-3 1.5 1.5 0 000 3z"/>
                                </svg>
                            </div>
                        </div>
                        <div class="ml-4 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-blue-100 truncate">Total Orders</dt>
                                <dd class="text-2xl font-bold text-white mt-1">{{ $totalOrders }}</dd>
                                <dd class="text-xs text-blue-100 mt-1">All time</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Orders This Month -->
            <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl shadow-lg overflow-hidden transform hover:scale-105 transition-transform duration-200">
                <div class="px-6 py-5">
                    <div class="flex items-center">
                        <div class="shrink-0">
                            <div class="w-12 h-12 bg-white/20 backdrop-blur-sm rounded-xl flex items-center justify-center">
                                <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/>
                                </svg>
                            </div>
                        </div>
                        <div class="ml-4 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-purple-100 truncate">This Month</dt>
                                <dd class="text-2xl font-bold text-white mt-1">{{ $ordersThisMonth }}</dd>
                                <dd class="text-xs text-purple-100 mt-1">{{ now()->format('F Y') }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Average Order Value -->
            <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-xl shadow-lg overflow-hidden transform hover:scale-105 transition-transform duration-200">
                <div class="px-6 py-5">
                    <div class="flex items-center">
                        <div class="shrink-0">
                            <div class="w-12 h-12 bg-white/20 backdrop-blur-sm rounded-xl flex items-center justify-center">
                                <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M8.433 7.418c.155-.103.346-.196.567-.267v1.698a2.305 2.305 0 01-.567-.267C8.07 8.34 8 8.114 8 8c0-.114.07-.34.433-.582zM11 12.849v-1.698c.22.071.412.164.567.267.364.243.433.468.433.582 0 .114-.07.34-.433.582a2.305 2.305 0 01-.567.267z"/>
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-13a1 1 0 10-2 0v.092a4.535 4.535 0 00-1.676.662C6.602 6.234 6 7.009 6 8c0 .99.602 1.765 1.324 2.246.48.32 1.054.545 1.676.662v1.941c-.391-.127-.68-.317-.843-.504a1 1 0 10-1.51 1.31c.562.649 1.413 1.076 2.353 1.253V15a1 1 0 102 0v-.092a4.535 4.535 0 001.676-.662C13.398 13.766 14 12.991 14 12c0-.99-.602-1.765-1.324-2.246A4.535 4.535 0 0011 9.092V7.151c.391.127.68.317.843.504a1 1 0 101.511-1.31c-.563-.649-1.413-1.076-2.354-1.253V5z" clip-rule="evenodd"/>
                                </svg>
                            </div>
                        </div>
                        <div class="ml-4 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-green-100 truncate">Avg Order Value</dt>
                                <dd class="text-2xl font-bold text-white mt-1">GHS {{ number_format($averageOrderValue, 2) }}</dd>
                                <dd class="text-xs text-green-100 mt-1">Per transaction</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Conversion Rate -->
            <div class="bg-gradient-to-br from-yellow-500 to-yellow-600 rounded-xl shadow-lg overflow-hidden transform hover:scale-105 transition-transform duration-200">
                <div class="px-6 py-5">
                    <div class="flex items-center">
                        <div class="shrink-0">
                            <div class="w-12 h-12 bg-white/20 backdrop-blur-sm rounded-xl flex items-center justify-center">
                                <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M12 7a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0V8.414l-4.293 4.293a1 1 0 01-1.414 0L8 10.414l-4.293 4.293a1 1 0 01-1.414-1.414l5-5a1 1 0 011.414 0L11 10.586 14.586 7H12z" clip-rule="evenodd"/>
                                </svg>
                            </div>
                        </div>
                        <div class="ml-4 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-yellow-100 truncate">Completion Rate</dt>
                                <dd class="text-2xl font-bold text-white mt-1">{{ number_format($completionRate, 1) }}%</dd>
                                <dd class="text-xs text-yellow-100 mt-1">Orders completed</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts and Detailed Analytics -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Sales Trend -->
            <x-card>
                <div class="px-6 py-5">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Sales Trend (Last 7 Days)</h3>
                    <div class="space-y-3">
                        @foreach($salesTrend as $day)
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-gray-600">{{ $day['date'] }}</span>
                                <div class="flex items-center space-x-3">
                                    <div class="w-48 bg-gray-200 rounded-full h-2">
                                        <div class="bg-gradient-to-r from-brand-deep-blue to-brand-green h-2 rounded-full" style="width: {{ $day['percentage'] }}%"></div>
                                    </div>
                                    <span class="text-sm font-semibold text-gray-900 w-20 text-right">GHS {{ number_format($day['amount'], 2) }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </x-card>

            <!-- Top Products -->
            <x-card>
                <div class="px-6 py-5">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Top Selling Products</h3>
                    <div class="space-y-4">
                        @forelse($topProducts as $product)
                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                <div class="flex-1">
                                    <p class="text-sm font-medium text-gray-900">{{ $product->name }}</p>
                                    <p class="text-xs text-gray-500">{{ $product->orders_count }} orders</p>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-semibold text-gray-900">GHS {{ number_format($product->total_sales, 2) }}</p>
                                    <p class="text-xs text-gray-500">Revenue</p>
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-gray-500 text-center py-8">No product sales data yet</p>
                        @endforelse
                    </div>
                </div>
            </x-card>
        </div>

        <!-- Order Status Breakdown -->
        <x-card>
            <div class="px-6 py-5">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Order Status Distribution</h3>
                <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                    <div class="p-4 bg-yellow-50 rounded-lg border border-yellow-200">
                        <p class="text-sm text-yellow-800 font-medium">Pending</p>
                        <p class="text-2xl font-bold text-yellow-900 mt-2">{{ $statusBreakdown['pending'] ?? 0 }}</p>
                        <p class="text-xs text-yellow-600 mt-1">{{ number_format(($statusBreakdown['pending'] ?? 0) / max($totalOrders, 1) * 100, 1) }}% of total</p>
                    </div>
                    <div class="p-4 bg-blue-50 rounded-lg border border-blue-200">
                        <p class="text-sm text-blue-800 font-medium">Processing</p>
                        <p class="text-2xl font-bold text-blue-900 mt-2">{{ $statusBreakdown['processing'] ?? 0 }}</p>
                        <p class="text-xs text-blue-600 mt-1">{{ number_format(($statusBreakdown['processing'] ?? 0) / max($totalOrders, 1) * 100, 1) }}% of total</p>
                    </div>
                    <div class="p-4 bg-green-50 rounded-lg border border-green-200">
                        <p class="text-sm text-green-800 font-medium">Completed</p>
                        <p class="text-2xl font-bold text-green-900 mt-2">{{ $statusBreakdown['completed'] ?? 0 }}</p>
                        <p class="text-xs text-green-600 mt-1">{{ number_format(($statusBreakdown['completed'] ?? 0) / max($totalOrders, 1) * 100, 1) }}% of total</p>
                    </div>
                    <div class="p-4 bg-red-50 rounded-lg border border-red-200">
                        <p class="text-sm text-red-800 font-medium">Failed</p>
                        <p class="text-2xl font-bold text-red-900 mt-2">{{ $statusBreakdown['failed'] ?? 0 }}</p>
                        <p class="text-xs text-red-600 mt-1">{{ number_format(($statusBreakdown['failed'] ?? 0) / max($totalOrders, 1) * 100, 1) }}% of total</p>
                    </div>
                    <div class="p-4 bg-gray-50 rounded-lg border border-gray-200">
                        <p class="text-sm text-gray-800 font-medium">Cancelled</p>
                        <p class="text-2xl font-bold text-gray-900 mt-2">{{ $statusBreakdown['cancelled'] ?? 0 }}</p>
                        <p class="text-xs text-gray-600 mt-1">{{ number_format(($statusBreakdown['cancelled'] ?? 0) / max($totalOrders, 1) * 100, 1) }}% of total</p>
                    </div>
                </div>
            </div>
        </x-card>

        <!-- Revenue Summary -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <x-card>
                <div class="px-6 py-5">
                    <h3 class="text-base font-semibold text-gray-900 mb-3">Today's Revenue</h3>
                    <p class="text-3xl font-bold text-gray-900">GHS {{ number_format($revenueToday, 2) }}</p>
                    <p class="text-sm text-gray-500 mt-2">{{ $ordersToday }} orders today</p>
                </div>
            </x-card>

            <x-card>
                <div class="px-6 py-5">
                    <h3 class="text-base font-semibold text-gray-900 mb-3">This Week's Revenue</h3>
                    <p class="text-3xl font-bold text-gray-900">GHS {{ number_format($revenueThisWeek, 2) }}</p>
                    <p class="text-sm text-gray-500 mt-2">{{ $ordersThisWeek }} orders this week</p>
                </div>
            </x-card>

            <x-card>
                <div class="px-6 py-5">
                    <h3 class="text-base font-semibold text-gray-900 mb-3">This Month's Revenue</h3>
                    <p class="text-3xl font-bold text-gray-900">GHS {{ number_format($revenueThisMonth, 2) }}</p>
                    <p class="text-sm text-gray-500 mt-2">{{ $ordersThisMonth }} orders this month</p>
                </div>
            </x-card>
        </div>
    </div>
</x-vendor-layout>
@endsection
