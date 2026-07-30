@extends('layouts.admin')

@section('title', 'Settings - XTRA4U Admin')
@section('description', 'Service availability, delivery notices, vendor approval, and vendor tier programs')

@section('content')
<x-admin-layout title="Settings" subtitle="Service availability, delivery notices, vendor approval, and vendor tier programs" active="settings">
    <div x-data="{ tab: '{{ $tab }}' }">
        {{-- Flash messages --}}
        @if (session('success'))
            <div class="mb-6 bg-green-50 border-l-4 border-green-500 rounded-lg p-4">
                <p class="text-sm font-medium text-green-800">{{ session('success') }}</p>
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-6 bg-red-50 border-l-4 border-red-500 rounded-lg p-4">
                <ul class="list-disc list-inside text-sm text-red-700 space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Tab navigation --}}
        <div class="mb-6 border-b border-gray-200 overflow-x-auto">
            <nav class="-mb-px flex gap-x-6 min-w-max" aria-label="Settings tabs">
                @foreach ([
                    ['key' => 'service-availability', 'label' => 'Service Availability'],
                    ['key' => 'delivery-status', 'label' => 'Delivery Status'],
                    ['key' => 'vendor-approval', 'label' => 'Vendor Approval'],
                    ['key' => 'vendor-tiers', 'label' => 'Vendor Tiers'],
                    ['key' => 'vendor-tier-promotions', 'label' => 'Tier Promotions'],
                    ['key' => 'vendor-tier-history', 'label' => 'Tier History'],
                ] as $t)
                    <button type="button"
                            @click="tab = '{{ $t['key'] }}'"
                            :class="tab === '{{ $t['key'] }}' ? 'border-brand-deep-blue text-brand-deep-blue' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                            class="whitespace-nowrap border-b-2 py-3 px-1 text-sm font-medium transition-colors">
                        {{ $t['label'] }}
                        @if ($t['key'] === 'vendor-tier-promotions' && $eligible->total() > 0)
                            <span class="ml-1.5 inline-flex items-center justify-center rounded-full bg-amber-100 text-amber-700 text-xs font-semibold px-2 py-0.5">{{ $eligible->total() }}</span>
                        @endif
                    </button>
                @endforeach
            </nav>
        </div>

        <div x-show="tab === 'service-availability'" x-cloak>
            @include('admin.settings.partials.service-availability')
        </div>

        <div x-show="tab === 'delivery-status'" x-cloak>
            @include('admin.settings.partials.delivery-status')
        </div>

        <div x-show="tab === 'vendor-approval'" x-cloak>
            @include('admin.settings.partials.vendor-approval')
        </div>

        <div x-show="tab === 'vendor-tiers'" x-cloak>
            @include('admin.settings.partials.vendor-tiers')
        </div>

        <div x-show="tab === 'vendor-tier-promotions'" x-cloak>
            @include('admin.settings.partials.vendor-tier-promotions')
        </div>

        <div x-show="tab === 'vendor-tier-history'" x-cloak>
            @include('admin.settings.partials.vendor-tier-history')
        </div>
    </div>
</x-admin-layout>
@endsection
