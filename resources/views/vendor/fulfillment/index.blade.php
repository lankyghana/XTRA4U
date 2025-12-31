@extends('layouts.vendor')

@section('title', 'Order Fulfillment - XTRA4U')

@section('content')
<x-vendor-layout :vendor="$vendor" title="Order Fulfillment" subtitle="Download processing orders and mark them completed" active="fulfillment">
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

        <!-- Available for Download -->
        <div class="bg-white rounded-xl shadow-lg border border-gray-200">
            <div class="px-6 py-5 border-b border-gray-100">
                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-bold text-gray-900">Available for Download</h2>
                        <p class="text-sm text-gray-600">Processing orders that haven’t been downloaded yet. Downloads are per network and never repeat the same order twice.</p>
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
                        @endphp
                        <div class="rounded-lg border border-gray-200 bg-gradient-to-br from-white to-gray-50 p-4">
                            <div class="flex items-start justify-between">
                                <div>
                                    <p class="text-sm font-semibold text-gray-900">{{ $network }}</p>
                                    <p class="text-xs text-gray-500 mt-1">{{ $count }} available</p>
                                </div>
                                @if($count > 0)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Ready</span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">None</span>
                                @endif
                            </div>

                            <div class="mt-4">
                                <a href="{{ route('vendor.fulfillment.download', ['network' => $network, 'limit' => (int)($limit ?? 2000)]) }}"
                                   class="w-full inline-flex items-center justify-center px-3 py-2 rounded-lg text-sm font-semibold text-white bg-brand-deep-blue hover:bg-brand-bright-blue transition-colors {{ $count === 0 ? 'opacity-50 pointer-events-none' : '' }}">
                                    Download {{ $network }} Orders
                                </a>
                                <p class="text-xs text-gray-500 mt-2">File format: <span class="font-mono">Number\tPackage</span></p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <!-- Downloaded awaiting completion -->
        <div class="bg-white rounded-xl shadow-lg border border-gray-200">
            <div class="px-6 py-5 border-b border-gray-100">
                <h2 class="text-lg font-bold text-gray-900">Downloaded (Awaiting Completion)</h2>
                <p class="text-sm text-gray-600">Orders you’ve already downloaded. Mark them as Completed after fulfilling them externally.</p>
            </div>
            <div class="p-6 space-y-6">
                @foreach($networks as $network)
                    @php
                        $downloadedCount = (int) ($downloadedByNetwork[$network] ?? 0);
                        $preview = $downloadedOrdersPreview[$network] ?? collect();
                    @endphp

                    <div class="rounded-lg border border-gray-200 overflow-hidden">
                        <div class="flex items-center justify-between px-4 py-3 bg-gray-50">
                            <div>
                                <p class="text-sm font-semibold text-gray-900">{{ $network }}</p>
                                <p class="text-xs text-gray-500">{{ $downloadedCount }} downloaded</p>
                            </div>
                            <form method="POST" action="{{ route('vendor.fulfillment.complete', ['network' => $network]) }}">
                                @csrf
                                <button type="submit"
                                        class="inline-flex items-center px-3 py-2 rounded-lg text-sm font-semibold text-white bg-green-600 hover:bg-green-700 transition-colors {{ $downloadedCount === 0 ? 'opacity-50 pointer-events-none' : '' }}">
                                    Mark Downloaded Orders as Completed
                                </button>
                            </form>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-white">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Order ID</th>
                                        <th class="px-4 py-3 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Number</th>
                                        <th class="px-4 py-3 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Package</th>
                                        <th class="px-4 py-3 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Downloaded</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-100">
                                    @forelse($preview as $order)
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-4 py-3 text-sm font-semibold text-gray-900">#{{ $order->id }}</td>
                                            <td class="px-4 py-3 text-sm text-gray-900">{{ $order->recipient_phone_number }}</td>
                                            <td class="px-4 py-3 text-sm text-gray-900">{{ $order->display_product_label }}</td>
                                            <td class="px-4 py-3 text-sm text-gray-500">{{ $order->downloaded_at?->format('M d, Y g:i A') ?? '—' }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="px-4 py-6 text-center text-sm text-gray-500">No downloaded orders for {{ $network }}.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        @if($downloadedCount > $preview->count())
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
