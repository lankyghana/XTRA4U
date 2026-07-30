<div>
    <div class="mb-4 flex justify-end">
        <a href="{{ route('admin.vendor-tiers.create') }}"
           class="inline-flex items-center gap-1.5 px-4 py-2 bg-brand-deep-blue text-white text-sm font-semibold rounded-lg hover:bg-brand-bright-blue transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            New Tier
        </a>
    </div>

    @if($tiers->isNotEmpty())
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-xl border border-gray-200 px-4 py-3">
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Total Tiers</p>
            <p class="text-2xl font-bold text-gray-900 mt-1">{{ $tiers->count() }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 px-4 py-3">
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Active</p>
            <p class="text-2xl font-bold text-green-600 mt-1">{{ $tiers->where('is_active', true)->count() }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 px-4 py-3">
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Vendors on Tiers</p>
            <p class="text-2xl font-bold text-gray-900 mt-1">{{ $tiers->sum('vendors_count') }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 px-4 py-3">
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">With Rules</p>
            <p class="text-2xl font-bold text-gray-900 mt-1">{{ $tiers->filter(fn ($t) => $t->rules->count() > 0)->count() }}</p>
        </div>
    </div>
    @endif

    <div class="space-y-3">
        @forelse($tiers as $tier)
        @php
            $accents = [
                ['left' => 'border-l-slate-400',  'bg' => 'bg-slate-100',  'text' => 'text-slate-700'],
                ['left' => 'border-l-blue-500',   'bg' => 'bg-blue-100',   'text' => 'text-blue-700'],
                ['left' => 'border-l-amber-500',  'bg' => 'bg-amber-100',  'text' => 'text-amber-700'],
                ['left' => 'border-l-purple-500', 'bg' => 'bg-purple-100', 'text' => 'text-purple-700'],
            ];
            $ac = $accents[min($loop->index, count($accents) - 1)];
            $discountLabel = $tier->discount_type === 'percentage'
                ? $tier->discount_value . '% off'
                : 'GHS ' . number_format($tier->discount_value, 2) . ' off';
        @endphp

        <div x-data="{ confirmDelete: false }"
             class="bg-white rounded-xl border border-gray-200 border-l-4 {{ $ac['left'] }} shadow-sm overflow-hidden">

            <div class="px-5 py-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-start gap-4 min-w-0">
                    <div class="flex-shrink-0 w-9 h-9 rounded-lg flex items-center justify-center text-xs font-bold {{ $ac['bg'] }} {{ $ac['text'] }}">
                        #{{ $loop->iteration }}
                    </div>

                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-sm font-bold text-gray-900">{{ $tier->name }}</h3>

                            @if($tier->is_active)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">
                                    <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span> Active
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">
                                    <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span> Inactive
                                </span>
                            @endif

                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold {{ $ac['bg'] }} {{ $ac['text'] }}">
                                {{ $discountLabel }}
                            </span>
                        </div>

                        @if($tier->description)
                            <p class="text-xs text-gray-500 mt-0.5">{{ $tier->description }}</p>
                        @endif

                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-2">
                            <span class="flex items-center gap-1 text-xs text-gray-500">
                                <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                                </svg>
                                <span class="font-semibold text-gray-700">{{ $tier->vendors_count ?? 0 }}</span>
                                vendor{{ ($tier->vendors_count ?? 0) !== 1 ? 's' : '' }}
                            </span>
                            <span class="flex items-center gap-1 text-xs text-gray-500">
                                <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                                </svg>
                                <span class="font-semibold text-gray-700">{{ $tier->rules->count() }}</span>
                                rule{{ $tier->rules->count() !== 1 ? 's' : '' }}
                            </span>
                            <span class="text-xs text-gray-400">Priority {{ $tier->priority }}</span>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-2 flex-shrink-0">
                    <a href="{{ route('admin.vendor-tiers.edit', $tier) }}"
                       class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                        </svg>
                        Edit
                    </a>

                    @if(! $tier->vendors()->exists() && ! $tier->eligibleVendors()->exists())
                        <button type="button" @click="confirmDelete = true"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-red-600 bg-red-50 hover:bg-red-100 rounded-lg transition-colors">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                            </svg>
                            Delete
                        </button>
                    @else
                        <span class="inline-flex items-center px-3 py-1.5 text-xs text-gray-400 bg-gray-50 rounded-lg cursor-not-allowed"
                              title="Cannot delete: vendors are assigned to this tier">
                            Delete
                        </span>
                    @endif
                </div>
            </div>

            @if($tier->rules->count() > 0)
                <div class="border-t border-gray-100 px-5 py-3 bg-gray-50">
                    <div class="flex flex-wrap gap-2">
                        @foreach($tier->rules as $rule)
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md bg-white border border-gray-200 text-xs text-gray-700 shadow-sm">
                                {{ \App\Models\VendorTierRule::supportedRuleKeys()[$rule->rule_key] ?? $rule->rule_key }}
                                <span class="font-mono text-gray-400 mx-0.5">{{ $rule->operator }}</span>
                                <span class="font-bold">{{ number_format((float) $rule->value, 0) }}</span>
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif

            <div x-show="confirmDelete" x-transition
                 class="border-t border-red-100 bg-red-50 px-5 py-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-red-700 font-medium">
                    Delete <strong>{{ $tier->name }}</strong>? This cannot be undone.
                </p>
                <div class="flex items-center gap-2 flex-shrink-0">
                    <button type="button" @click="confirmDelete = false"
                            class="px-3 py-1.5 text-xs font-semibold text-gray-600 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                        Cancel
                    </button>
                    <form method="POST" action="{{ route('admin.vendor-tiers.destroy', $tier) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                                class="px-3 py-1.5 text-xs font-semibold text-white bg-red-600 hover:bg-red-700 rounded-lg transition-colors">
                            Yes, delete
                        </button>
                    </form>
                </div>
            </div>
        </div>
        @empty
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
                <div class="px-6 py-16 text-center">
                    <div class="mx-auto w-12 h-12 rounded-full bg-gray-100 flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                        </svg>
                    </div>
                    <h3 class="text-sm font-semibold text-gray-900">No tiers defined</h3>
                    <p class="mt-1 text-sm text-gray-500">Create your first vendor tier to get started.</p>
                    <a href="{{ route('admin.vendor-tiers.create') }}"
                       class="mt-4 inline-flex items-center gap-1.5 px-4 py-2 bg-brand-deep-blue text-white text-sm font-semibold rounded-lg hover:bg-brand-bright-blue transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        Create Tier
                    </a>
                </div>
            </div>
        @endforelse
    </div>
</div>
