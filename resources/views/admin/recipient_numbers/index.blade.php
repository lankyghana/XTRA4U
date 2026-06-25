@extends('layouts.admin')

@section('title', 'Recipient Numbers - Admin Portal')

@section('content')
<x-admin-layout title="Recipient Numbers" subtitle="High-volume audit log of recipient phone numbers used across orders and AFA" active="recipient-numbers">
    <div class="space-y-6">
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-sm font-semibold text-gray-900">Filters</h2>
                    <p class="text-xs text-gray-500">Narrow down logs by date range, phone number, service type, or vendor.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <x-button href="{{ route('admin.recipient-numbers.export', array_merge(request()->query(), ['format' => 'txt'])) }}" variant="outline" size="sm">Export TXT</x-button>
                    <x-button href="{{ route('admin.recipient-numbers.export', array_merge(request()->query(), ['format' => 'csv'])) }}" variant="outline" size="sm">Export CSV</x-button>
                    <x-button href="{{ route('admin.recipient-numbers.copy', request()->query()) }}" variant="outline" size="sm">Copy View</x-button>
                </div>
            </div>

            <div class="p-5">
                <form method="GET" action="{{ route('admin.recipient-numbers.index') }}" class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-4">
                        <div class="lg:col-span-3">
                            <label class="block text-xs font-medium text-gray-600 mb-1" for="recipient_date_from">Date from</label>
                            <input id="recipient_date_from" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-brand-deep-blue focus:border-brand-deep-blue" />
                        </div>
                        <div class="lg:col-span-3">
                            <label class="block text-xs font-medium text-gray-600 mb-1" for="recipient_date_to">Date to</label>
                            <input id="recipient_date_to" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-brand-deep-blue focus:border-brand-deep-blue" />
                        </div>
                        <div class="lg:col-span-3">
                            <label class="block text-xs font-medium text-gray-600 mb-1" for="recipient_phone">Phone number</label>
                            <input id="recipient_phone" type="text" name="phone" value="{{ $filters['phone'] ?? '' }}" placeholder="+233… or partial" class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-brand-deep-blue focus:border-brand-deep-blue" />
                        </div>
                        <div class="lg:col-span-3">
                            <label class="block text-xs font-medium text-gray-600 mb-1" for="recipient_vendor_id">Vendor ID</label>
                            <input id="recipient_vendor_id" type="number" name="vendor_id" value="{{ $filters['vendor_id'] ?? '' }}" placeholder="Optional" class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-brand-deep-blue focus:border-brand-deep-blue" />
                        </div>
                        <div class="lg:col-span-6">
                            <label class="block text-xs font-medium text-gray-600 mb-1" for="recipient_service_type">Service type</label>
                            <input id="recipient_service_type" type="text" name="service_type" value="{{ $filters['service_type'] ?? '' }}" placeholder="e.g. DATA or afa_registration (partial match)" class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-brand-deep-blue focus:border-brand-deep-blue" />
                        </div>
                        <div class="lg:col-span-6 flex items-end">
                            <label class="flex items-start gap-3 cursor-pointer select-none">
                                <input id="recipient_distinct" type="checkbox" name="distinct" value="1" {{ ($filters['distinct'] ?? null) ? 'checked' : '' }} class="mt-0.5 w-4 h-4 rounded border-gray-300 text-brand-deep-blue focus:ring-brand-deep-blue" />
                                <span>
                                    <span class="block text-sm font-medium text-gray-700">Unique numbers only</span>
                                    <span class="block text-xs text-gray-500">Exports and Copy deduplicate to one row per phone number</span>
                                </span>
                            </label>
                        </div>
                    </div>

                    @if($filters['distinct'] ?? null)
                        <div class="rounded-lg bg-amber-50 border border-amber-200 px-4 py-2 text-xs text-amber-800">
                            <strong>Unique numbers mode active</strong> — Exports and Copy View will output each phone number exactly once. The table below still shows all matching rows.
                        </div>
                    @endif

                    <div class="flex flex-col sm:flex-row gap-2 sm:justify-end">
                        <x-button type="submit" variant="primary" class="justify-center">Apply filters</x-button>
                        <x-button href="{{ route('admin.recipient-numbers.index') }}" variant="outline" class="justify-center">Reset</x-button>
                    </div>
                    <p class="text-xs text-gray-500">Exports stream in chunks (memory-safe) and may take time on large datasets. Without filters, exports may contain duplicate phone numbers (same number across different vendors or service types) unless <em>Unique numbers only</em> is checked.</p>
                </form>
            </div>
        </div>

        <x-table :headers="['Used at', 'Phone number', 'Service type', 'Vendor', 'Order']">
            @forelse($logs as $log)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 text-sm text-gray-600 whitespace-nowrap">
                        <div class="text-gray-900 font-medium">{{ optional($log->used_at)->format('M d, Y') }}</div>
                        <div class="text-xs text-gray-500">{{ optional($log->used_at)->format('H:i') }}</div>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-900 whitespace-nowrap">
                        <span class="font-mono font-medium bg-gray-100 px-2 py-1 rounded">{{ $log->phone_number }}</span>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-700">
                        <span class="font-mono text-xs bg-gray-100 px-2 py-1 rounded inline-block">{{ $log->service_type }}</span>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-700">
                        @if($log->vendor)
                            <div class="font-medium">{{ $log->vendor->name }}</div>
                            <div class="text-xs text-gray-500">#{{ $log->vendor_id }} • {{ $log->vendor->vendor_code }}</div>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-700">
                        @if($log->order_id)
                            <span class="font-mono text-xs bg-gray-100 px-2 py-1 rounded inline-block">#{{ $log->order_id }}</span>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-6 py-10 text-center">
                        <div class="text-sm font-medium text-gray-900">No recipient numbers found</div>
                        <div class="text-sm text-gray-500 mt-1">Try widening your date range or clearing filters.</div>
                    </td>
                </tr>
            @endforelse
        </x-table>

        @php($canPaginate = is_object($logs) && method_exists($logs, 'hasPages'))
        @if ($canPaginate && $logs->hasPages())
            <div class="flex justify-end pt-2">
                {{ $logs->links() }}
            </div>
        @endif
    </div>
</x-admin-layout>
@endsection
