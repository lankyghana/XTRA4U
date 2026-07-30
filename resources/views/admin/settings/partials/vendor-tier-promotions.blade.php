<div>
    @if($eligible->isEmpty())
        <x-card>
            <div class="px-6 py-16 text-center">
                <svg class="mx-auto h-12 w-12 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <h3 class="mt-3 text-sm font-medium text-gray-900">No pending promotions</h3>
                <p class="mt-1 text-sm text-gray-500">All eligible vendors have been reviewed. The qualification engine runs daily.</p>
            </div>
        </x-card>
    @else
        <div class="space-y-4">
            @foreach($eligible as $vendor)
                @php
                    $perf = $vendor->performance;
                    $currentTier = $vendor->tier;
                    $nextTier = $vendor->eligibleTier;
                @endphp
                <x-card x-data="{ showActions: false }">
                    <div class="px-4 py-4 sm:px-6">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <h3 class="text-base font-semibold text-gray-900">{{ $vendor->name }}</h3>
                                    <span class="text-xs text-gray-500 font-mono bg-gray-100 px-2 py-0.5 rounded">{{ $vendor->vendor_code }}</span>
                                </div>
                                <p class="text-sm text-gray-500 mt-0.5">{{ $vendor->email }}</p>

                                <!-- Tier change indicator -->
                                <div class="flex items-center gap-2 mt-2">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium bg-gray-100 text-gray-700">
                                        {{ $currentTier?->name ?? 'None' }}
                                    </span>
                                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                                    </svg>
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-semibold bg-green-100 text-green-800">
                                        {{ $nextTier?->name ?? 'Unknown' }}
                                    </span>
                                    @if($nextTier)
                                        <span class="text-xs text-green-600 font-medium">
                                            +{{ $nextTier->discount_value }}% discount
                                        </span>
                                    @endif
                                </div>

                                <!-- Performance snapshot -->
                                <div class="mt-3 grid grid-cols-2 sm:grid-cols-4 gap-2">
                                    <div class="bg-gray-50 rounded-lg p-2 text-center">
                                        <div class="text-xs text-gray-500">Orders</div>
                                        <div class="text-sm font-bold text-gray-900">{{ number_format($perf['min_affiliate_orders']) }}</div>
                                    </div>
                                    <div class="bg-gray-50 rounded-lg p-2 text-center">
                                        <div class="text-xs text-gray-500">Success Rate</div>
                                        <div class="text-sm font-bold text-gray-900">{{ number_format($perf['min_success_rate'], 1) }}%</div>
                                    </div>
                                    <div class="bg-gray-50 rounded-lg p-2 text-center">
                                        <div class="text-xs text-gray-500">Sales Volume</div>
                                        <div class="text-sm font-bold text-gray-900">GHS {{ number_format($perf['min_affiliate_sales'], 0) }}</div>
                                    </div>
                                    <div class="bg-gray-50 rounded-lg p-2 text-center">
                                        <div class="text-xs text-gray-500">Account Age</div>
                                        <div class="text-sm font-bold text-gray-900">{{ $perf['min_account_age_days'] }}d</div>
                                    </div>
                                </div>

                                @if($vendor->tier_qualified_at)
                                    <p class="text-xs text-gray-400 mt-2">Qualified {{ $vendor->tier_qualified_at->diffForHumans() }}</p>
                                @endif
                            </div>

                            <!-- Actions -->
                            <div class="flex items-center gap-2 flex-shrink-0">
                                <button type="button" @click="showActions = !showActions"
                                        class="px-3 py-1.5 text-xs font-semibold text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                                    Review
                                </button>
                            </div>
                        </div>

                        <!-- Expandable action panel -->
                        <div x-show="showActions" x-cloak class="mt-4 pt-4 border-t border-gray-100">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <!-- Approve -->
                                <form method="POST" action="{{ route('admin.vendor-tier-promotions.approve', $vendor) }}">
                                    @csrf
                                    <div class="p-3 bg-green-50 border border-green-200 rounded-lg">
                                        <p class="text-xs font-semibold text-green-800 mb-2">Approve Promotion</p>
                                        <textarea name="notes" rows="2" placeholder="Optional notes..."
                                                  class="w-full text-xs rounded border-green-300 focus:border-green-500 focus:ring-green-500 mb-2 resize-none"></textarea>
                                        <button type="submit"
                                                class="w-full px-3 py-1.5 bg-green-600 text-white text-xs font-semibold rounded-lg hover:bg-green-700 transition-colors">
                                            Confirm Promotion to {{ $nextTier?->name }}
                                        </button>
                                    </div>
                                </form>

                                <!-- Reject -->
                                <form method="POST" action="{{ route('admin.vendor-tier-promotions.reject', $vendor) }}">
                                    @csrf
                                    <div class="p-3 bg-red-50 border border-red-200 rounded-lg">
                                        <p class="text-xs font-semibold text-red-800 mb-2">Reject Promotion</p>
                                        <textarea name="notes" rows="2" placeholder="Reason for rejection..."
                                                  class="w-full text-xs rounded border-red-300 focus:border-red-500 focus:ring-red-500 mb-2 resize-none"></textarea>
                                        <button type="submit"
                                                class="w-full px-3 py-1.5 bg-red-600 text-white text-xs font-semibold rounded-lg hover:bg-red-700 transition-colors">
                                            Reject
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </x-card>
            @endforeach
        </div>

        <div class="mt-6">
            {{ $eligible->links() }}
        </div>
    @endif
</div>
