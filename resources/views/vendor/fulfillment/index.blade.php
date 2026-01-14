@extends('layouts.vendor')

@section('title', 'Order Fulfillment - XTRA4U')

@section('content')
<x-vendor-layout :vendor="$vendor" title="Order Fulfillment" subtitle="Download processing orders and mark them completed" active="fulfillment">
    @php
        $formatDateTime = function ($value): string {
            if ($value instanceof \Carbon\CarbonInterface) {
                return $value->format('M d, Y g:i A');
            }

            if (is_string($value) && trim($value) !== '') {
                try {
                    return \Illuminate\Support\Carbon::parse($value)->format('M d, Y g:i A');
                } catch (\Throwable $e) {
                    // Fall through
                }
            }

            return '—';
        };
    @endphp
    <div class="space-y-6">
        @if(session('success'))
            <div class="bg-gradient-to-r from-green-50 to-emerald-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg shadow-md">
                <div class="flex items-center">
                    <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                    {{ session('success') }}
                </div>
            </div>
        @endif

        @if(session('status'))
            <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 text-blue-800 px-4 py-3 rounded-lg shadow-md">
                <div class="flex items-center">
                    <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                    </svg>
                    {{ session('status') }}
                </div>
            </div>
        @endif

        <!-- Guidance -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="bg-gradient-to-br from-white to-gray-50 border border-gray-200 rounded-xl p-5 shadow-sm">
                <div class="flex items-start gap-3">
                    <div class="mt-0.5 w-9 h-9 rounded-lg bg-brand-deep-blue/10 flex items-center justify-center">
                        <svg class="w-5 h-5 text-brand-deep-blue" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-gray-900">Manual fulfillment (Download → Deliver → Mark Completed)</p>
                        <p class="text-sm text-gray-600 mt-1">Use this for networks you fulfill outside the API. Downloads never repeat the same order twice.</p>
                        <p class="text-xs text-gray-500 mt-2">Tip: after delivery, click “Mark Downloaded Orders as Completed” to update status + SMS.</p>
                    </div>
                </div>
            </div>

            <div class="bg-gradient-to-br from-emerald-50 to-white border border-emerald-200 rounded-xl p-5 shadow-sm">
                <div class="flex items-start gap-3">
                    <div class="mt-0.5 w-9 h-9 rounded-lg bg-emerald-600/10 flex items-center justify-center">
                        <svg class="w-5 h-5 text-emerald-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-gray-900">External API fulfillment (Sent via API)</p>
                        <p class="text-sm text-gray-700 mt-1">Orders sent via API are excluded from downloads to prevent double-fulfillment.</p>
                        <p class="text-xs text-gray-600 mt-2">When you confirm delivery in your provider dashboard, use the “External API” section below to mark them completed.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Available / External API Pending Confirmation -->
        <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden">
            <div class="px-6 py-5 border-b border-gray-100 bg-gradient-to-r from-gray-50 to-white">
                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-bold text-gray-900">Work Queues</h2>
                        <p class="text-sm text-gray-600">Download-ready orders per network, plus External API orders awaiting your confirmation.</p>
                    </div>

                    <form method="GET" action="{{ route('vendor.fulfillment.index') }}" class="flex items-end gap-2">
                        <div>
                            <label for="limit" class="block text-xs font-semibold text-gray-700 mb-1">Download batch size</label>
                            <select id="limit" name="limit" class="text-sm border-gray-300 rounded-lg focus:ring-brand-bright-blue focus:border-brand-bright-blue px-3 py-2 font-medium shadow-sm">
                                @php
                                    $limitOptions = [100, 250, 500, 1000, 2000, 5000, 10000];
                                @endphp
                                @foreach($limitOptions as $opt)
                                    <option value="{{ $opt }}" {{ (int)($limit ?? 2000) === (int)$opt ? 'selected' : '' }}>
                                        {{ number_format($opt) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <button type="submit" class="inline-flex items-center px-3 py-2 rounded-lg text-sm font-semibold text-white bg-brand-deep-blue hover:bg-brand-bright-blue transition-colors">
                            Apply
                        </button>
                    </form>
                </div>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    @foreach($networks as $network)
                        @php
                            $count = (int) ($availableByNetwork[$network] ?? 0);
                            $isExternalApi = $network === 'External API';
                        @endphp
                        <div class="rounded-xl border border-gray-200 bg-gradient-to-br from-white to-gray-50 p-4 shadow-sm hover:shadow-md transition-shadow">
                            <div class="flex items-start justify-between">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <p class="text-sm font-semibold text-gray-900">{{ $network }}</p>
                                        @if($isExternalApi)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800">API</span>
                                        @endif
                                    </div>
                                    <p class="text-xs text-gray-500 mt-1">
                                        {{ $count }} {{ $isExternalApi ? 'sent via API (awaiting your confirmation)' : 'available' }}
                                    </p>
                                </div>
                                @if($count > 0)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-800">Ready</span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-600">None</span>
                                @endif
                            </div>

                            <div class="mt-4">
                                @if($isExternalApi)
                                    <div class="flex flex-col gap-2">
                                        <div class="w-full inline-flex items-center justify-center px-3 py-2 rounded-lg text-sm font-semibold text-emerald-900 bg-emerald-100 border border-emerald-200">
                                            Awaiting your confirmation
                                        </div>
                                        <p class="text-xs text-gray-600">Use the “External API” completion button below after confirming delivery.</p>
                                        @php
                                            // Count failed API orders for this vendor (for button visibility)
                                            $failedApiCount = 
                                                \App\Models\Order::query()
                                                    ->where('status', 'Processing')
                                                    ->where('external_fulfillment_status', 'failed')
                                                    ->where(function($q) use ($vendor) {
                                                        $q->where('vendor_id', $vendor->id)
                                                          ->orWhere('owner_vendor_id', $vendor->id);
                                                    })
                                                    ->count();
                                        @endphp
                                        @if($failedApiCount > 0)
                                            <form method="POST" action="{{ route('vendor.fulfillment.resend-failed-api') }}" onsubmit="return confirm('Resend all failed External API orders? This will attempt to send them again.');">
                                                @csrf
                                                <button type="submit" class="inline-flex items-center px-3 py-2 rounded-lg text-sm font-semibold text-white bg-emerald-700 hover:bg-emerald-800 transition-colors w-full mt-2">
                                                    Resend All Failed Orders ({{ $failedApiCount }})
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                @else
                                    <a href="{{ route('vendor.fulfillment.download', ['network' => $network, 'limit' => (int)($limit ?? 2000)]) }}"
                                       class="w-full inline-flex items-center justify-center px-3 py-2 rounded-lg text-sm font-semibold text-white bg-brand-deep-blue hover:bg-brand-bright-blue transition-colors {{ $count === 0 ? 'opacity-50 pointer-events-none' : '' }}">
                                        Download {{ $network }} Orders
                                    </a>
                                    <p class="text-xs text-gray-500 mt-2">File format: <span class="font-mono">Number\tPackage</span></p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <!-- Completion queues -->
        <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden">
            <div class="px-6 py-5 border-b border-gray-100 bg-gradient-to-r from-gray-50 to-white">
                <h2 class="text-lg font-bold text-gray-900">Completion</h2>
                <p class="text-sm text-gray-600">Mark downloaded orders (manual) or API-sent orders (external) as Completed.</p>
            </div>
            <div class="p-6 space-y-6">
                @foreach($networks as $network)
                    @php
                        $downloadedCount = (int) ($downloadedByNetwork[$network] ?? 0);
                        $preview = $downloadedOrdersPreview[$network] ?? collect();
                        $isExternalApi = $network === 'External API';
                    @endphp

                    <div class="rounded-lg border border-gray-200 overflow-hidden">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 px-4 py-3 bg-gray-50">
                            <div>
                                <p class="text-sm font-semibold text-gray-900">{{ $network }}</p>
                                <p class="text-xs text-gray-500">
                                    {{ $isExternalApi ? ((int)($availableByNetwork[$network] ?? 0)) . ' sent via API' : ($downloadedCount . ' downloaded') }}
                                </p>
                            </div>
                            @if(!$isExternalApi)
                                <form method="POST" action="{{ route('vendor.fulfillment.complete', ['network' => $network]) }}">
                                    @csrf
                                    <button type="submit"
                                            class="inline-flex items-center px-3 py-2 rounded-lg text-sm font-semibold text-white bg-green-600 hover:bg-green-700 transition-colors {{ $downloadedCount === 0 ? 'opacity-50 pointer-events-none' : '' }}">
                                        Mark Downloaded Orders as Completed
                                    </button>
                                </form>
                            @endif
                        </div>

                        @if($isExternalApi)
                            @php
                                $apiOrders = $externalApiOrders ?? collect();
                                $apiOrderIds = $apiOrders instanceof \Illuminate\Pagination\Paginator || $apiOrders instanceof \Illuminate\Pagination\LengthAwarePaginator
                                    ? $apiOrders->getCollection()->pluck('id')->values()->all()
                                    : collect($apiOrders)->pluck('id')->values()->all();
                            @endphp
                            <div class="overflow-x-auto"
                                x-data="{
                                    confirm: false,
                                    selectedOrderIds: [],
                                    pageOrderIds: @json($apiOrderIds),
                                    toggleAll(event) {
                                        const checked = !!event.target.checked;
                                        this.selectedOrderIds = checked ? [...this.pageOrderIds] : [];
                                    },
                                    isAllSelected() {
                                        return this.pageOrderIds.length > 0 && this.selectedOrderIds.length === this.pageOrderIds.length;
                                    },
                                    selectionLabel() {
                                        const count = this.selectedOrderIds.length;
                                        if (count === 0) return 'No orders selected';
                                        return count + ' order' + (count === 1 ? '' : 's') + ' selected';
                                    }
                                }">
                        @else
                            <div class="overflow-x-auto">
                        @endif
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-white">
                                    <tr>
                                        @if($isExternalApi)
                                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-700 uppercase tracking-wider w-10">
                                                <label class="inline-flex items-center" title="Select all on this page">
                                                    <input type="checkbox" class="h-4 w-4 rounded border-gray-300 text-emerald-700 focus:ring-emerald-600"
                                                        @change="toggleAll($event)" :checked="isAllSelected()">
                                                    <span class="sr-only">Select all</span>
                                                </label>
                                            </th>
                                        @endif
                                        <th class="px-4 py-3 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Order ID</th>
                                        <th class="px-4 py-3 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Number</th>
                                        <th class="px-4 py-3 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Package</th>
                                        <th class="px-4 py-3 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">{{ $isExternalApi ? 'Sent to API' : 'Downloaded' }}</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-100">
                                    @if($isExternalApi)
                                        <tr>
                                            <td colspan="5" class="px-4 py-4 bg-gradient-to-r from-emerald-50 to-white border-b border-emerald-100">
                                                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                                    <div>
                                                        <p class="text-sm font-semibold text-emerald-900">External API completion</p>
                                                        <p class="text-xs text-emerald-800 mt-1">Select the specific API orders you have confirmed as delivered in your provider dashboard, then mark only those as completed.</p>
                                                    </div>

                                                    <form id="external-api-complete-form" method="POST" action="{{ route('vendor.fulfillment.complete', ['network' => $network]) }}" class="w-full sm:w-auto">
                                                        @csrf
                                                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-end">
                                                            <div class="flex items-center gap-2">
                                                                <label class="inline-flex items-center text-xs font-semibold text-emerald-900 bg-white border border-emerald-200 rounded-lg px-3 py-2 shadow-sm">
                                                                    <input type="checkbox" class="mr-2 rounded border-gray-300" x-model="confirm" name="confirm_external_api_completed" value="1">
                                                                    I confirm selected orders were delivered
                                                                </label>

                                                                <span class="text-xs font-semibold text-gray-700" x-text="selectionLabel()"></span>
                                                            </div>

                                                            <button type="submit"
                                                                    :disabled="!confirm || selectedOrderIds.length === 0"
                                                                    class="inline-flex items-center justify-center px-4 py-2 rounded-lg text-sm font-semibold text-white transition-colors"
                                                                    :class="(!confirm || selectedOrderIds.length === 0) ? 'bg-emerald-700/40 cursor-not-allowed' : 'bg-emerald-700 hover:bg-emerald-800'">
                                                                Mark Selected as Completed
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>

                                        @forelse(($externalApiOrders ?? collect()) as $order)
                                            <tr class="hover:bg-emerald-50/40">
                                                <td class="px-4 py-3">
                                                    <input
                                                        type="checkbox"
                                                        class="h-4 w-4 rounded border-gray-300 text-emerald-700 focus:ring-emerald-600"
                                                        value="{{ (int) $order->id }}"
                                                        name="order_ids[]"
                                                        form="external-api-complete-form"
                                                        x-model="selectedOrderIds"
                                                    />
                                                </td>
                                                <td class="px-4 py-3 text-sm font-semibold text-gray-900">#{{ $order->id }}</td>
                                                <td class="px-4 py-3 text-sm text-gray-900">{{ $order->recipient_phone_number }}</td>
                                                <td class="px-4 py-3 text-sm text-gray-900">{{ $order->display_product_label }}</td>
                                                <td class="px-4 py-3 text-sm text-gray-600">
                                                    {{ $formatDateTime($order->external_fulfillment_completed_at) }}
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-500">
                                                    No External API orders awaiting confirmation.
                                                </td>
                                            </tr>
                                        @endforelse
                                    @else
                                        @forelse($preview as $order)
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-4 py-3 text-sm font-semibold text-gray-900">#{{ $order->id }}</td>
                                                <td class="px-4 py-3 text-sm text-gray-900">{{ $order->recipient_phone_number }}</td>
                                                <td class="px-4 py-3 text-sm text-gray-900">{{ $order->display_product_label }}</td>
                                                <td class="px-4 py-3 text-sm text-gray-500">
                                                    {{ $formatDateTime($order->downloaded_at) }}
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="4" class="px-4 py-6 text-center text-sm text-gray-500">
                                                    {{ 'No downloaded orders for ' . $network . '.' }}
                                                </td>
                                            </tr>
                                        @endforelse
                                    @endif
                                </tbody>
                            </table>
                        </div>

                        @if($isExternalApi && isset($externalApiOrders) && method_exists($externalApiOrders, 'hasPages') && $externalApiOrders->hasPages())
                            <div class="px-4 py-4 bg-white border-t border-gray-100">
                                {{ $externalApiOrders->links() }}
                            </div>
                        @endif

                        @if(!$isExternalApi && $downloadedCount > $preview->count())
                            <div class="px-4 py-3 bg-gray-50 text-xs text-gray-500">
                                Showing latest {{ $preview->count() }}. Mark completed to clear this list.
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        <div class="text-xs text-gray-500">
            This fulfillment feature is vendor-internal only. Customers never see any "downloaded" state.
        </div>
    </div>
</x-vendor-layout>
@endsection
