@extends('layouts.admin')

@section('title', 'Edit Tier: ' . $vendorTier->name)

@section('content')
<x-admin-layout title="Edit Tier: {{ $vendorTier->name }}" subtitle="Update tier settings and qualification rules" active="vendor-tiers">
    <x-slot name="actions">
        <a href="{{ route('admin.vendor-tiers.index') }}"
           class="px-4 py-2 text-sm font-semibold text-gray-600 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
            Back to Tiers
        </a>
    </x-slot>

    <form method="POST" action="{{ route('admin.vendor-tiers.update', $vendorTier) }}" x-data="tierForm()">
        @csrf
        @method('PUT')

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

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 space-y-6">
                <!-- General Settings -->
                <x-card>
                    <div class="px-4 py-5 sm:p-6 space-y-5">
                        <h2 class="text-base font-semibold text-gray-900">General Settings</h2>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Tier Name <span class="text-red-500">*</span></label>
                            <input type="text" name="name" value="{{ old('name', $vendorTier->name) }}" required
                                   class="w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-deep-blue focus:ring-brand-deep-blue text-sm">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                            <textarea name="description" rows="2"
                                      class="w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-deep-blue focus:ring-brand-deep-blue text-sm">{{ old('description', $vendorTier->description) }}</textarea>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Priority <span class="text-red-500">*</span></label>
                                <input type="number" name="priority" value="{{ old('priority', $vendorTier->priority) }}" min="0" max="999" required
                                       class="w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-deep-blue focus:ring-brand-deep-blue text-sm">
                                <p class="text-xs text-gray-500 mt-1">Higher number = higher tier.</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Discount Type</label>
                                <select name="discount_type"
                                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-deep-blue focus:ring-brand-deep-blue text-sm">
                                    <option value="percentage" {{ old('discount_type', $vendorTier->discount_type) === 'percentage' ? 'selected' : '' }}>Percentage (%)</option>
                                    <option value="fixed" {{ old('discount_type', $vendorTier->discount_type) === 'fixed' ? 'selected' : '' }}>Fixed (GHS)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Discount Value</label>
                                <input type="number" name="discount_value" value="{{ old('discount_value', $vendorTier->discount_value) }}" min="0" max="100" step="0.01"
                                       class="w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-deep-blue focus:ring-brand-deep-blue text-sm">
                            </div>
                        </div>

                        <div class="flex items-center gap-3">
                            <input type="checkbox" name="is_active" id="is_active" value="1"
                                   {{ old('is_active', $vendorTier->is_active) ? 'checked' : '' }}
                                   class="h-4 w-4 rounded border-gray-300 text-brand-deep-blue focus:ring-brand-deep-blue">
                            <label for="is_active" class="text-sm font-medium text-gray-700">Active</label>
                        </div>
                    </div>
                </x-card>

                <!-- Qualification Rules -->
                <x-card>
                    <div class="px-4 py-5 sm:p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="text-base font-semibold text-gray-900">Qualification Rules</h2>
                            <button type="button" @click="addRule()"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-brand-deep-blue bg-blue-50 hover:bg-blue-100 rounded-lg transition-colors">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                </svg>
                                Add Rule
                            </button>
                        </div>

                        <p class="text-sm text-gray-500 mb-4">Vendors must satisfy ALL rules to become eligible for promotion to this tier.</p>

                        <div class="space-y-3">
                            <template x-for="(rule, index) in rules" :key="index">
                                <div class="flex items-center gap-2 p-3 bg-gray-50 rounded-lg">
                                    <select :name="`rules[${index}][rule_key]`"
                                            x-model="rule.rule_key"
                                            class="flex-1 rounded-lg border-gray-300 shadow-sm focus:border-brand-deep-blue focus:ring-brand-deep-blue text-sm">
                                        <option value="">Select rule...</option>
                                        @foreach($ruleKeys as $key => $label)
                                            <option value="{{ $key }}" :selected="rule.rule_key === '{{ $key }}'">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <select :name="`rules[${index}][operator]`"
                                            x-model="rule.operator"
                                            class="w-20 rounded-lg border-gray-300 shadow-sm focus:border-brand-deep-blue focus:ring-brand-deep-blue text-sm">
                                        @foreach($operators as $op)
                                            <option value="{{ $op }}" :selected="rule.operator === '{{ $op }}'">{{ $op }}</option>
                                        @endforeach
                                    </select>
                                    <input type="number" :name="`rules[${index}][value]`"
                                           x-model="rule.value"
                                           class="w-28 rounded-lg border-gray-300 shadow-sm focus:border-brand-deep-blue focus:ring-brand-deep-blue text-sm"
                                           placeholder="Value" step="0.01" min="0">
                                    <button type="button" @click="removeRule(index)"
                                            class="p-1.5 text-gray-400 hover:text-red-500 rounded transition-colors">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>
                            </template>

                            <div x-show="rules.length === 0" class="text-sm text-gray-400 text-center py-4">
                                No rules defined. Vendors will not automatically qualify for this tier.
                            </div>
                        </div>
                    </div>
                </x-card>
            </div>

            <!-- Sidebar -->
            <div class="space-y-6">
                <x-card>
                    <div class="px-4 py-5 sm:p-6">
                        <h2 class="text-sm font-semibold text-gray-900 mb-3">Tier Stats</h2>
                        <dl class="space-y-2">
                            <div class="flex justify-between">
                                <dt class="text-sm text-gray-500">Vendors on tier</dt>
                                <dd class="text-sm font-semibold text-gray-900">{{ $vendorTier->vendors()->count() }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-sm text-gray-500">Eligible vendors</dt>
                                <dd class="text-sm font-semibold text-gray-900">{{ $vendorTier->eligibleVendors()->count() }}</dd>
                            </div>
                        </dl>
                    </div>
                </x-card>

                <x-card>
                    <div class="px-4 py-5 sm:p-6 space-y-3">
                        <x-button type="submit" variant="primary" class="w-full justify-center">
                            Save Changes
                        </x-button>
                        <a href="{{ route('admin.vendor-tiers.index') }}"
                           class="block w-full text-center px-4 py-2 text-sm font-semibold text-gray-600 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                            Cancel
                        </a>
                    </div>
                </x-card>
            </div>
        </div>
    </form>
</x-admin-layout>
@endsection

@push('scripts')
<script>
function tierForm() {
    return {
        rules: @json(old('rules', $vendorTier->rules->map(fn($r) => ['rule_key' => $r->rule_key, 'operator' => $r->operator, 'value' => (float)$r->value])->values()->all())),
        addRule() {
            this.rules.push({ rule_key: '', operator: '>=', value: '' });
        },
        removeRule(index) {
            this.rules.splice(index, 1);
        }
    };
}
</script>
@endpush
