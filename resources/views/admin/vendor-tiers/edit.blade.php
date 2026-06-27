@extends('layouts.admin')

@section('title', 'Edit Tier: ' . $vendorTier->name)

@section('content')
<x-admin-layout title="Edit Tier: {{ $vendorTier->name }}" subtitle="Update settings and qualification rules" active="vendor-tiers">
    <x-slot name="actions">
        <a href="{{ route('admin.vendor-tiers.index') }}"
           class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-gray-600 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            Back to Tiers
        </a>
    </x-slot>

    <form method="POST" action="{{ route('admin.vendor-tiers.update', $vendorTier) }}"
          x-data="tierForm()"
          @submit="submitting = true">
        @csrf
        @method('PUT')

        @if(session('success'))
            <div class="mb-6 rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800 flex items-center gap-2">
                <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                </svg>
                {{ session('success') }}
            </div>
        @endif

        @if($errors->any())
            <div class="mb-6 rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">
                @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 space-y-6">

                {{-- General --}}
                <x-card>
                    <div class="px-4 py-5 sm:p-6 space-y-5">
                        <div class="flex items-center justify-between">
                            <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wide">General</h2>
                            <label for="is_active" class="flex items-center gap-2.5 cursor-pointer select-none">
                                <span class="text-sm font-medium text-gray-700">Active</span>
                                <div class="relative inline-flex">
                                    <input type="checkbox" name="is_active" id="is_active" value="1"
                                           {{ old('is_active', $vendorTier->is_active) ? 'checked' : '' }}
                                           class="sr-only peer">
                                    <div class="w-10 h-6 rounded-full bg-gray-200 transition-colors
                                                peer-checked:bg-brand-deep-blue
                                                peer-focus-visible:ring-2 peer-focus-visible:ring-brand-deep-blue peer-focus-visible:ring-offset-1"></div>
                                    <div class="absolute top-[2px] left-[2px] w-5 h-5 rounded-full bg-white shadow-sm transition-transform
                                                peer-checked:translate-x-4"></div>
                                </div>
                            </label>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Tier Name <span class="text-red-500">*</span>
                            </label>
                            <input type="text" name="name" value="{{ old('name', $vendorTier->name) }}" required
                                   class="w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-deep-blue focus:ring-brand-deep-blue text-sm">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                            <textarea name="description" rows="2"
                                      class="w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-deep-blue focus:ring-brand-deep-blue text-sm">{{ old('description', $vendorTier->description) }}</textarea>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Priority <span class="text-red-500">*</span>
                            </label>
                            <div class="flex items-center gap-3">
                                <input type="number" name="priority" value="{{ old('priority', $vendorTier->priority) }}"
                                       min="0" max="999" required
                                       class="w-28 rounded-lg border-gray-300 shadow-sm focus:border-brand-deep-blue focus:ring-brand-deep-blue text-sm">
                                <p class="text-xs text-gray-500">Higher number = higher position in the tier hierarchy.</p>
                            </div>
                        </div>
                    </div>
                </x-card>

                {{-- Discount --}}
                <x-card>
                    <div class="px-4 py-5 sm:p-6 space-y-4">
                        <div>
                            <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Discount</h2>
                            <p class="text-xs text-gray-400 mt-0.5">Reduces the affiliate's buying price from the parent vendor. Customer-facing prices are not affected.</p>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                                <select name="discount_type" x-model="discountType"
                                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-deep-blue focus:ring-brand-deep-blue text-sm">
                                    <option value="percentage">Percentage (%)</option>
                                    <option value="fixed">Fixed Amount (GHS)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Value</label>
                                <div class="relative">
                                    <span class="absolute inset-y-0 left-3 flex items-center text-sm text-gray-400 pointer-events-none"
                                          x-text="discountType === 'percentage' ? '%' : '₵'"></span>
                                    <input type="number" name="discount_value" x-model="discountValue"
                                           min="0" max="100" step="0.01"
                                           class="w-full pl-8 rounded-lg border-gray-300 shadow-sm focus:border-brand-deep-blue focus:ring-brand-deep-blue text-sm">
                                </div>
                            </div>
                        </div>

                        <div class="rounded-lg bg-blue-50 border border-blue-100 px-4 py-3">
                            <p class="text-xs font-semibold text-blue-700 mb-1">Live Preview</p>
                            <p class="text-xs text-blue-600">
                                On a <span class="font-semibold">GHS 100</span> product, affiliates on this tier pay
                                <span class="font-bold" x-text="previewPrice()"></span>
                                <template x-if="parseFloat(discountValue) > 0">
                                    <span> — saving <span x-text="savingsLabel()"></span></span>
                                </template>
                                <template x-if="parseFloat(discountValue) <= 0">
                                    <span class="text-blue-400"> (no discount yet)</span>
                                </template>
                            </p>
                        </div>
                    </div>
                </x-card>

                {{-- Qualification Rules --}}
                <x-card>
                    <div class="px-4 py-5 sm:p-6">
                        <div class="flex items-center justify-between mb-1">
                            <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Qualification Rules</h2>
                            <button type="button" @click="addRule()"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-brand-deep-blue bg-blue-50 hover:bg-blue-100 rounded-lg transition-colors">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                                </svg>
                                Add Rule
                            </button>
                        </div>
                        <p class="text-xs text-gray-400 mb-4">
                            Vendors must satisfy <strong class="text-gray-600">all</strong> rules to become eligible for promotion to this tier.
                        </p>

                        <div x-show="rules.length > 0"
                             class="grid gap-2 px-3 mb-1"
                             style="grid-template-columns: 1fr 72px 96px 32px">
                            <p class="text-xs font-medium text-gray-400 uppercase tracking-wide">Metric</p>
                            <p class="text-xs font-medium text-gray-400 uppercase tracking-wide">Cond.</p>
                            <p class="text-xs font-medium text-gray-400 uppercase tracking-wide">Value</p>
                            <span></span>
                        </div>

                        <div class="space-y-2">
                            <template x-for="(rule, index) in rules" :key="index">
                                <div class="grid items-center gap-2 px-3 py-2.5 bg-gray-50 rounded-lg border border-gray-100"
                                     style="grid-template-columns: 1fr 72px 96px 32px">
                                    <select :name="`rules[${index}][rule_key]`"
                                            x-model="rule.rule_key"
                                            class="rounded-lg border-gray-300 shadow-sm focus:border-brand-deep-blue focus:ring-brand-deep-blue text-sm">
                                        <option value="">Select metric…</option>
                                        @foreach($ruleKeys as $key => $label)
                                            <option value="{{ $key }}" :selected="rule.rule_key === '{{ $key }}'">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <select :name="`rules[${index}][operator]`"
                                            x-model="rule.operator"
                                            class="rounded-lg border-gray-300 shadow-sm focus:border-brand-deep-blue focus:ring-brand-deep-blue text-sm font-mono text-center">
                                        @foreach($operators as $op)
                                            <option value="{{ $op }}" :selected="rule.operator === '{{ $op }}'">{{ $op }}</option>
                                        @endforeach
                                    </select>
                                    <input type="number" :name="`rules[${index}][value]`"
                                           x-model="rule.value"
                                           placeholder="0" step="0.01" min="0"
                                           class="rounded-lg border-gray-300 shadow-sm focus:border-brand-deep-blue focus:ring-brand-deep-blue text-sm">
                                    <button type="button" @click="removeRule(index)"
                                            class="w-8 h-8 flex items-center justify-center text-gray-400 hover:text-red-500 hover:bg-red-50 rounded-lg transition-colors">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                        </svg>
                                    </button>
                                </div>
                            </template>

                            <div x-show="rules.length === 0"
                                 class="flex flex-col items-center justify-center py-10 border-2 border-dashed border-gray-200 rounded-lg text-center">
                                <svg class="w-8 h-8 text-gray-300 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                                </svg>
                                <p class="text-sm text-gray-400">No rules defined.</p>
                                <p class="text-xs text-gray-400 mt-0.5">Vendors will not automatically qualify for this tier.</p>
                            </div>
                        </div>
                    </div>
                </x-card>
            </div>

            {{-- Sidebar --}}
            <div class="space-y-4">
                <x-card>
                    <div class="px-4 py-5 sm:p-6">
                        <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">Tier Stats</h2>
                        <dl class="space-y-3">
                            <div class="flex items-center justify-between">
                                <dt class="flex items-center gap-1.5 text-sm text-gray-600">
                                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    </svg>
                                    On this tier
                                </dt>
                                <dd class="text-sm font-bold text-gray-900">{{ $vendorTier->vendors()->count() }}</dd>
                            </div>
                            <div class="flex items-center justify-between">
                                <dt class="flex items-center gap-1.5 text-sm text-gray-600">
                                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    Eligible for promotion
                                </dt>
                                <dd class="text-sm font-bold text-gray-900">{{ $vendorTier->eligibleVendors()->count() }}</dd>
                            </div>
                        </dl>
                    </div>
                </x-card>

                <x-card>
                    <div class="px-4 py-5 sm:p-6 space-y-3">
                        <button type="submit"
                                :disabled="submitting"
                                class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-brand-deep-blue text-white text-sm font-semibold rounded-lg hover:bg-brand-bright-blue transition-colors disabled:opacity-60 disabled:cursor-not-allowed">
                            <svg x-show="submitting" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            <span x-text="submitting ? 'Saving…' : 'Save Changes'">Save Changes</span>
                        </button>
                        <a href="{{ route('admin.vendor-tiers.index') }}"
                           class="block w-full text-center px-4 py-2 text-sm font-semibold text-gray-600 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                            Cancel
                        </a>
                    </div>
                </x-card>

                <div class="px-1 space-y-0.5">
                    <p class="text-xs text-gray-400">Slug: <code class="font-mono text-gray-500">{{ $vendorTier->slug }}</code></p>
                    <p class="text-xs text-gray-400">Created {{ $vendorTier->created_at->diffForHumans() }}</p>
                </div>
            </div>
        </div>
    </form>
</x-admin-layout>
@endsection

@push('scripts')
@php
    $existingRules = old('rules', $vendorTier->rules->map(fn ($r) => [
        'rule_key' => $r->rule_key,
        'operator' => $r->operator,
        'value'    => (float) $r->value,
    ])->values()->all());
@endphp
<script>
function tierForm() {
    return {
        rules: @json($existingRules),
        discountType: '{{ old('discount_type', $vendorTier->discount_type) }}',
        discountValue: {{ (float) old('discount_value', $vendorTier->discount_value) }},
        submitting: false,

        previewPrice() {
            const val = parseFloat(this.discountValue) || 0;
            const price = this.discountType === 'percentage'
                ? Math.max(0, 100 - val)
                : Math.max(0, 100 - val);
            return 'GHS ' + price.toFixed(2);
        },

        savingsLabel() {
            const val = parseFloat(this.discountValue) || 0;
            return this.discountType === 'percentage'
                ? val.toFixed(2) + '%'
                : 'GHS ' + val.toFixed(2);
        },

        addRule() {
            this.rules.push({ rule_key: '', operator: '>=', value: '' });
        },

        removeRule(index) {
            this.rules.splice(index, 1);
        },
    };
}
</script>
@endpush
