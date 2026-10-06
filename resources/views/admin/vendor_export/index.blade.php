@extends('layouts.admin')

@section('title', 'Vendor Contact Export - Admin Portal')

@section('content')
<x-admin-layout title="Vendor Contact Export" subtitle="Download vendor contact details as TXT, CSV, Excel or phone-book (VCF) files" active="vendors">
    @php
        $inputClass = 'w-full border border-gray-300 rounded-lg text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-brand-violet focus:border-brand-violet';
        $labelClass = 'block text-xs font-medium text-gray-600 mb-1';
        $limits = ['100' => '100', '500' => '500', '1000' => '1,000', '5000' => '5,000', '10000' => '10,000', 'all' => 'All matching', 'custom' => 'Custom amount'];
    @endphp

    <form method="POST" action="{{ route('admin.vendors.export.download') }}" id="vendor-export-form" class="space-y-6"
          x-data="vendorContactExport({ previewUrl: @js(route('admin.vendors.export.preview')) })" x-ref="form">
        @csrf

        <div class="flex items-center justify-between">
            <a href="{{ route('admin.vendors.index', array_filter(['status' => $initial['status'] !== 'all' ? $initial['status'] : null, 'q' => $initial['q']])) }}" class="text-sm text-brand-violet hover:underline">&larr; Back to vendors</a>
        </div>

        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800">
            Vendor phone numbers and emails are sensitive. Every export is recorded in the audit log (who, when, filters, counts) and is streamed to you without being stored on the server.
        </div>

        <div x-cloak x-show="errors.length" role="alert" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <ul class="list-disc pl-5"><template x-for="e in errors" :key="e"><li x-text="e"></li></template></ul>
        </div>
        @if ($errors->any())
            <div role="alert" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        {{-- FILTER VENDORS --}}
        <div class="bg-white rounded-xl border border-gray-200">
            <div class="px-5 py-4 border-b border-gray-100">
                <h2 class="text-sm font-semibold text-gray-900">1. Filter vendors</h2>
                <p class="text-xs text-gray-500">Choose which vendors are eligible for the export.</p>
            </div>
            <div class="p-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-4">
                <div class="lg:col-span-3">
                    <label class="{{ $labelClass }}" for="ve-status">Status</label>
                    <select id="ve-status" name="status" class="{{ $inputClass }}" @change="invalidate()">
                        @foreach (['all' => 'All vendors', 'approved' => 'Approved', 'pending' => 'Pending / Suspended'] as $value => $label)
                            <option value="{{ $value }}" @selected($initial['status'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="lg:col-span-3">
                    <label class="{{ $labelClass }}" for="ve-tier">Tier</label>
                    <select id="ve-tier" name="tier_id" class="{{ $inputClass }}" @change="invalidate()">
                        <option value="">All tiers</option>
                        @foreach ($tiers as $tier)
                            <option value="{{ $tier->id }}" @selected((string) $initial['tier_id'] === (string) $tier->id)>{{ $tier->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="lg:col-span-3">
                    <label class="{{ $labelClass }}" for="ve-from">Registered from</label>
                    <input id="ve-from" type="date" name="registered_from" value="{{ $initial['registered_from'] }}" class="{{ $inputClass }}" @change="invalidate()">
                </div>
                <div class="lg:col-span-3">
                    <label class="{{ $labelClass }}" for="ve-to">Registered to</label>
                    <input id="ve-to" type="date" name="registered_to" value="{{ $initial['registered_to'] }}" class="{{ $inputClass }}" @change="invalidate()">
                </div>
                <div class="sm:col-span-2 lg:col-span-12">
                    <label class="{{ $labelClass }}" for="ve-q">Search</label>
                    <input id="ve-q" type="search" name="q" maxlength="100" value="{{ $initial['q'] }}" placeholder="Name, email, phone or vendor code" class="{{ $inputClass }}" @input="invalidate()">
                </div>
            </div>
        </div>

        {{-- EXPORT OPTIONS --}}
        <div class="bg-white rounded-xl border border-gray-200">
            <div class="px-5 py-4 border-b border-gray-100">
                <h2 class="text-sm font-semibold text-gray-900">2. Contacts to export</h2>
                <p class="text-xs text-gray-500">The limit is applied after invalid and duplicate numbers are removed.</p>
            </div>
            <div class="p-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-4">
                <div class="lg:col-span-4">
                    <label class="{{ $labelClass }}" for="ve-limit">Number of contacts</label>
                    <select id="ve-limit" name="limit" x-model="limit" class="{{ $inputClass }}" @change="invalidate()">
                        @foreach ($limits as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="lg:col-span-3" x-cloak x-show="limit === 'custom'">
                    <label class="{{ $labelClass }}" for="ve-custom">Custom amount (max {{ number_format($maxCustomLimit) }})</label>
                    <input id="ve-custom" type="number" name="custom_limit" min="1" max="{{ $maxCustomLimit }}" step="1" x-bind:required="limit === 'custom'" x-bind:disabled="limit !== 'custom'" class="{{ $inputClass }}" @input="invalidate()">
                </div>
                <div class="lg:col-span-5">
                    <label class="{{ $labelClass }}" for="ve-order">Which contacts come first</label>
                    <select id="ve-order" name="order" class="{{ $inputClass }}" @change="invalidate()">
                        @foreach ($orders as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <label class="lg:col-span-6 flex items-start gap-3 cursor-pointer select-none">
                    <input type="checkbox" name="remove_duplicates" value="1" checked class="mt-0.5 w-4 h-4 rounded border-gray-300 text-brand-violet focus:ring-brand-violet" @change="invalidate()">
                    <span>
                        <span class="block text-sm font-medium text-gray-700">Remove duplicate numbers</span>
                        <span class="block text-xs text-gray-500">0241234567 and +233241234567 count as the same number</span>
                    </span>
                </label>
                <label class="lg:col-span-6 flex items-start gap-3 cursor-pointer select-none">
                    <input type="checkbox" name="valid_only" value="1" checked class="mt-0.5 w-4 h-4 rounded border-gray-300 text-brand-violet focus:ring-brand-violet" @change="invalidate()">
                    <span>
                        <span class="block text-sm font-medium text-gray-700">Only vendors with valid phone numbers</span>
                        <span class="block text-xs text-gray-500">Skips empty or unrecognisable numbers</span>
                    </span>
                </label>
                <div class="lg:col-span-4">
                    <label class="{{ $labelClass }}" for="ve-phone-format">Phone number style</label>
                    <select id="ve-phone-format" name="phone_format" class="{{ $inputClass }}" @change="invalidate()">
                        <option value="local">Local (0241234567)</option>
                        <option value="international">International (+233241234567)</option>
                    </select>
                </div>
            </div>
        </div>

        {{-- FORMAT --}}
        <div class="bg-white rounded-xl border border-gray-200">
            <div class="px-5 py-4 border-b border-gray-100">
                <h2 class="text-sm font-semibold text-gray-900">3. Format</h2>
            </div>
            <div class="p-5 space-y-5">
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                    @foreach (['txt' => ['TXT', 'Plain text, one per line'], 'csv' => ['CSV', 'Spreadsheets & other systems'], 'xlsx' => ['Excel (.xlsx)', 'Microsoft Excel workbook'], 'vcf' => ['Contacts (.vcf)', 'Import into phones & Google Contacts']] as $value => [$label, $hint])
                        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 p-3 hover:border-brand-violet has-[:checked]:border-brand-violet has-[:checked]:bg-brand-violet-soft">
                            <input type="radio" name="format" value="{{ $value }}" x-model="format" class="mt-0.5 text-brand-violet focus:ring-brand-violet" @change="invalidate()">
                            <span>
                                <span class="block text-sm font-medium text-gray-900">{{ $label }}</span>
                                <span class="block text-xs text-gray-500">{{ $hint }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>

                {{-- TXT --}}
                <div x-cloak x-show="format === 'txt'" class="max-w-sm">
                    <label class="{{ $labelClass }}" for="ve-txt-style">Each line contains</label>
                    <select id="ve-txt-style" name="txt_style" x-bind:disabled="format !== 'txt'" class="{{ $inputClass }}" @change="invalidate()">
                        <option value="phones">Phone number only</option>
                        <option value="name_phone">Name - Phone</option>
                    </select>
                </div>

                {{-- CSV / XLSX --}}
                <fieldset x-cloak x-show="format === 'csv' || format === 'xlsx'">
                    <legend class="{{ $labelClass }}">Fields to include</legend>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                        @foreach ($fields as $key => $label)
                            <label class="flex items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" name="fields[]" value="{{ $key }}" @checked(in_array($key, ['name', 'phone'], true)) x-bind:disabled="format === 'txt' || format === 'vcf'" class="w-4 h-4 rounded border-gray-300 text-brand-violet focus:ring-brand-violet" @change="invalidate()">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                {{-- VCF --}}
                <div x-cloak x-show="format === 'vcf'" class="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-2xl">
                    <div>
                        <label class="{{ $labelClass }}" for="ve-vcf-name">Contact name</label>
                        <select id="ve-vcf-name" name="vcf_name" x-bind:disabled="format !== 'vcf'" class="{{ $inputClass }}" @change="invalidate()">
                            <option value="xtra4u_vendor_name">XTRA4U - Vendor Name</option>
                            <option value="vendor_name">Vendor Name</option>
                        </select>
                    </div>
                    <fieldset>
                        <legend class="{{ $labelClass }}">Also include</legend>
                        <div class="flex gap-4">
                            @foreach (['email' => 'Email', 'vendor_code' => 'Vendor code (note)'] as $key => $label)
                                <label class="flex items-center gap-2 text-sm text-gray-700">
                                    <input type="checkbox" name="fields[]" value="{{ $key }}" x-bind:disabled="format !== 'vcf'" class="w-4 h-4 rounded border-gray-300 text-brand-violet focus:ring-brand-violet" @change="invalidate()">
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                </div>
            </div>
        </div>

        {{-- SUMMARY + DOWNLOAD --}}
        <div class="bg-white rounded-xl border border-gray-200">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-3">
                <h2 class="text-sm font-semibold text-gray-900">Summary</h2>
                <x-button type="button" variant="outline" size="sm" @click="loadPreview()" x-bind:disabled="loading">
                    <span x-text="loading ? 'Calculating…' : 'Preview summary'"></span>
                </x-button>
            </div>
            <div class="p-5 space-y-4">
                <dl x-cloak x-show="summary" class="grid grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
                    <div><dt class="text-xs text-gray-500">Matching vendors</dt><dd class="font-semibold text-gray-900" x-text="fmt(summary?.matching)"></dd></div>
                    <div><dt class="text-xs text-gray-500">Contacts to download</dt><dd class="font-semibold text-gray-900" x-text="fmt(summary?.exported)"></dd></div>
                    <div><dt class="text-xs text-gray-500">Skipped (no usable phone)</dt><dd class="font-semibold text-gray-900" x-text="fmt(summary?.skipped_invalid)"></dd></div>
                    <div><dt class="text-xs text-gray-500">Duplicates removed</dt><dd class="font-semibold text-gray-900" x-text="fmt(summary?.duplicates_removed)"></dd></div>
                </dl>
                <p x-cloak x-show="summary" class="text-xs text-gray-500">Skipped and duplicate counts cover the vendors reviewed to reach your limit.</p>
                <p x-show="!summary" class="text-sm text-gray-500">Run “Preview summary” to see how many contacts this export will contain.</p>

                <div class="flex flex-wrap gap-2">
                    <x-button type="submit" variant="primary">
                        <span x-text="summary ? 'Download ' + fmt(summary.exported) + ' contacts' : 'Download contacts'"></span>
                    </x-button>
                    <x-button :href="route('admin.vendors.index')" variant="secondary">Cancel</x-button>
                </div>
            </div>
        </div>
    </form>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('vendorContactExport', (config) => ({
                limit: '1000',
                format: 'txt',
                summary: null,
                loading: false,
                errors: [],
                fmt(n) { return Number(n ?? 0).toLocaleString(); },
                invalidate() { this.summary = null; this.errors = []; },
                async loadPreview() {
                    this.loading = true; this.errors = [];
                    try {
                        const res = await fetch(config.previewUrl, {
                            method: 'POST',
                            body: new FormData(this.$el),
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            credentials: 'same-origin',
                        });
                        if (res.status === 422) {
                            const data = await res.json();
                            this.errors = Object.values(data.errors || {}).flat();
                            this.summary = null;
                        } else if (!res.ok) {
                            this.errors = ['Could not calculate the summary (error ' + res.status + '). Please try again.'];
                        } else {
                            this.summary = await res.json();
                        }
                    } catch (e) {
                        this.errors = ['Network error while calculating the summary.'];
                    } finally {
                        this.loading = false;
                    }
                },
            }));
        });
    </script>
</x-admin-layout>
@endsection
