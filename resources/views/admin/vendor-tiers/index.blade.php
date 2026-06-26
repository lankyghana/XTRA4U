@extends('layouts.admin')

@section('title', 'Vendor Tiers')

@section('content')
<x-admin-layout title="Vendor Tiers" subtitle="Manage tier definitions, discounts, and qualification rules" active="vendor-tiers">
    <x-slot name="actions">
        <a href="{{ route('admin.vendor-tiers.create') }}"
           class="inline-flex items-center gap-1.5 px-4 py-2 bg-brand-deep-blue text-white text-sm font-semibold rounded-lg hover:bg-brand-bright-blue transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            New Tier
        </a>
    </x-slot>

    @if(session('success'))
        <div class="mb-6 rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div class="mb-6 rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <div class="space-y-4">
        @forelse($tiers as $tier)
            <x-card>
                <div class="px-4 py-4 sm:px-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-4">
                        <!-- Priority badge -->
                        <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold
                            {{ $tier->priority === 0 ? 'bg-gray-100 text-gray-600' : ($tier->priority === 1 ? 'bg-blue-100 text-blue-700' : ($tier->priority === 2 ? 'bg-yellow-100 text-yellow-700' : 'bg-purple-100 text-purple-700')) }}">
                            #{{ $tier->priority + 1 }}
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="text-base font-semibold text-gray-900">{{ $tier->name }}</h3>
                                @if(! $tier->is_active)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">Inactive</span>
                                @endif
                            </div>
                            @if($tier->description)
                                <p class="text-sm text-gray-500 mt-0.5">{{ $tier->description }}</p>
                            @endif
                            <div class="flex flex-wrap gap-3 mt-1.5">
                                <span class="text-xs text-gray-500">
                                    <span class="font-medium text-gray-700">Discount:</span>
                                    {{ $tier->discount_type === 'percentage' ? $tier->discount_value . '%' : 'GHS ' . number_format($tier->discount_value, 2) }}
                                </span>
                                <span class="text-xs text-gray-500">
                                    <span class="font-medium text-gray-700">Vendors:</span>
                                    {{ $tier->vendors_count ?? 0 }}
                                </span>
                                <span class="text-xs text-gray-500">
                                    <span class="font-medium text-gray-700">Rules:</span>
                                    {{ $tier->rules->count() }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 flex-shrink-0">
                        <a href="{{ route('admin.vendor-tiers.edit', $tier) }}"
                           class="px-3 py-1.5 text-xs font-semibold text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                            Edit
                        </a>
                        @if(! $tier->vendors()->exists() && ! $tier->eligibleVendors()->exists())
                            <form method="POST" action="{{ route('admin.vendor-tiers.destroy', $tier) }}"
                                  onsubmit="return confirm('Delete tier \'{{ $tier->name }}\'? This cannot be undone.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                        class="px-3 py-1.5 text-xs font-semibold text-red-600 bg-red-50 hover:bg-red-100 rounded-lg transition-colors">
                                    Delete
                                </button>
                            </form>
                        @endif
                    </div>
                </div>

                @if($tier->rules->count() > 0)
                    <div class="border-t border-gray-100 px-4 py-3 sm:px-6 bg-gray-50 rounded-b-xl">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Qualification Rules</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach($tier->rules as $rule)
                                <span class="inline-flex items-center px-2.5 py-1 rounded-md bg-white border border-gray-200 text-xs text-gray-700">
                                    {{ \App\Models\VendorTierRule::supportedRuleKeys()[$rule->rule_key] ?? $rule->rule_key }}
                                    <span class="mx-1 text-gray-400">{{ $rule->operator }}</span>
                                    <span class="font-semibold">{{ number_format((float)$rule->value, 0) }}</span>
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endif
            </x-card>
        @empty
            <x-card>
                <div class="px-6 py-12 text-center">
                    <svg class="mx-auto h-12 w-12 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                    </svg>
                    <h3 class="mt-3 text-sm font-medium text-gray-900">No tiers defined</h3>
                    <p class="mt-1 text-sm text-gray-500">Create your first vendor tier to get started.</p>
                    <div class="mt-4">
                        <a href="{{ route('admin.vendor-tiers.create') }}"
                           class="inline-flex items-center px-4 py-2 bg-brand-deep-blue text-white text-sm font-semibold rounded-lg hover:bg-brand-bright-blue transition-colors">
                            Create Tier
                        </a>
                    </div>
                </div>
            </x-card>
        @endforelse
    </div>
</x-admin-layout>
@endsection
