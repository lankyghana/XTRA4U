@extends('layouts.vendor')

@section('title', 'AFA Settings - XTRA4U')

@section('content')
<x-vendor-layout :vendor="$vendor" title="AFA Settings" subtitle="Configure your AFA registration service" active="afa">
    <div class="space-y-6">
        @if(session('success'))
            <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl">
                <div class="flex items-center">
                    <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                    {{ session('success') }}
                </div>
            </div>
        @endif
        
        @if(session('error'))
            <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-xl">
                <div class="flex items-center">
                    <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                    </svg>
                    {{ session('error') }}
                </div>
            </div>
        @endif

        <!-- Back Button -->
        <a href="{{ route('vendor.afa.index') }}" class="inline-flex items-center text-gray-600 hover:text-brand-violet transition-colors">
            <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            Back to Registrations
        </a>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Settings Form -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h2 class="text-base font-semibold text-gray-900 flex items-center gap-2">
                            <span class="w-9 h-9 bg-brand-violet-soft rounded-lg flex items-center justify-center">
                                <svg class="w-5 h-5 text-brand-violet" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                </svg>
                            </span>
                            AFA Registration Settings
                        </h2>
                        <p class="text-sm text-gray-500 mt-1">Choose how you want to offer AFA service</p>
                    </div>

                    <!-- Mode Selection Tabs -->
                    <div class="border-b border-gray-200">
                        <nav class="flex -mb-px">
                            <button 
                                type="button"
                                onclick="switchMode('direct')"
                                id="tab-direct"
                                class="w-1/2 py-4 px-6 text-center font-medium transition-colors {{ $vendor->afa_enabled ? 'border-b-2 border-green-500 text-green-600 bg-green-50' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-50' }}"
                            >
                                <svg class="w-5 h-5 inline-block mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                Direct Provider
                            </button>
                            <button 
                                type="button"
                                onclick="switchMode('reseller')"
                                id="tab-reseller"
                                class="w-1/2 py-4 px-6 text-center font-medium transition-colors {{ $vendor->afa_reseller_enabled ? 'border-b-2 border-blue-500 text-blue-600 bg-blue-50' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-50' }}"
                            >
                                <svg class="w-5 h-5 inline-block mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                                </svg>
                                Reseller Mode
                            </button>
                        </nav>
                    </div>

                    <!-- Direct Provider Form -->
                    <div id="form-direct" class="{{ $vendor->afa_reseller_enabled ? 'hidden' : '' }}">
                        <form method="POST" action="{{ route('vendor.afa.update-settings') }}" class="p-6">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="afa_mode" value="direct">

                            <!-- Enable/Disable Service -->
                            <div class="mb-6">
                                <label class="flex items-center cursor-pointer">
                                    <input 
                                        type="checkbox" 
                                        name="afa_enabled" 
                                        value="1"
                                        class="w-5 h-5 text-green-600 border-gray-300 rounded focus:ring-green-500"
                                        {{ $vendor->afa_enabled ? 'checked' : '' }}
                                    >
                                    <span class="ml-3 text-gray-900 font-medium">Enable AFA Registration Service</span>
                                </label>
                                <p class="mt-1 ml-8 text-sm text-gray-500">
                                    When enabled, customers can submit AFA registrations through your storefront.
                                </p>
                            </div>

                            <!-- Price Setting -->
                            <div class="mb-6">
                                <label for="afa_price" class="block text-sm font-medium text-gray-700 mb-2">
                                    Registration Price (GH₵)
                                </label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <span class="text-gray-500 sm:text-sm">GH₵</span>
                                    </div>
                                    <input 
                                        type="number" 
                                        name="afa_price" 
                                        id="afa_price"
                                        step="0.01"
                                        min="1"
                                        value="{{ old('afa_price', $vendor->afa_price ?? 50) }}"
                                        class="w-full pl-12 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 text-lg font-semibold"
                                        placeholder="50.00"
                                    >
                                </div>
                                <p class="mt-1 text-sm text-gray-500">
                                    This is the amount customers will pay for AFA registration.
                                </p>
                                @error('afa_price')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                        <!-- Earnings Preview -->
                            <div class="mb-6 bg-gray-50 rounded-lg p-4">
                                <h4 class="text-sm font-medium text-gray-700 mb-2">Earnings Preview</h4>
                                <div class="space-y-2">
                                    <div class="flex justify-between text-sm">
                                        <span class="text-gray-500">Customer Pays:</span>
                                        <span class="font-medium text-gray-900" id="preview-customer-pays">GH₵ {{ number_format($vendor->afa_price ?? 50, 2) }}</span>
                                    </div>
                                    <div class="border-t border-gray-200 pt-2 flex justify-between">
                                        <span class="font-medium text-gray-700">Your Earning:</span>
                                        <span class="font-bold text-green-600" id="preview-your-earning">GH₵ {{ number_format(($vendor->afa_price ?? 50) * 0.98, 2) }}</span>
                                    </div>
                                </div>
                            </div>

                            <button type="submit" class="w-full px-6 py-3 bg-green-600 text-white font-medium rounded-lg hover:bg-green-700 transition-colors">
                                Save Direct Provider Settings
                            </button>
                        </form>
                    </div>

                    <!-- Reseller Form -->
                    <div id="form-reseller" class="{{ !$vendor->afa_reseller_enabled ? 'hidden' : '' }}">
                        <form method="POST" action="{{ route('vendor.afa.update-settings') }}" class="p-6">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="afa_mode" value="reseller">

                            <div class="mb-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
                                <div class="flex">
                                    <svg class="w-5 h-5 text-blue-500 mr-2 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                                    </svg>
                                    <div class="text-sm text-blue-700">
                                        <p class="font-medium">Reseller Mode</p>
                                        <p class="mt-1">As a reseller, you sell another vendor's AFA service with your own markup. The source vendor processes the registration, and you earn your markup.</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Select Source Vendor -->
                            <div class="mb-6">
                                <label for="afa_source_vendor_id" class="block text-sm font-medium text-gray-700 mb-2">
                                    Select AFA Provider
                                </label>
                                <select 
                                    name="afa_source_vendor_id" 
                                    id="afa_source_vendor_id"
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                    onchange="updateResellerPreview()"
                                >
                                    <option value="">-- Select a vendor --</option>
                                    @foreach($availableAfaVendors ?? [] as $afaVendor)
                                        <option 
                                            value="{{ $afaVendor->id }}" 
                                            data-price="{{ $afaVendor->afa_price }}"
                                            {{ $vendor->afa_source_vendor_id == $afaVendor->id ? 'selected' : '' }}
                                        >
                                            {{ $afaVendor->name }} ({{ $afaVendor->vendor_code }}) - GH₵{{ number_format($afaVendor->afa_price, 2) }}
                                        </option>
                                    @endforeach
                                </select>
                                @if(empty($availableAfaVendors) || $availableAfaVendors->isEmpty())
                                    <p class="mt-1 text-sm text-yellow-600">No eligible AFA provider found. You can only resell AFA from your affiliate parent vendor (and only if they have AFA enabled).</p>
                                @else
                                    <p class="mt-1 text-sm text-gray-500">You can only resell AFA from your affiliate parent vendor.</p>
                                @endif
                                @error('afa_source_vendor_id')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <!-- Your Markup -->
                            <div class="mb-6">
                                <label for="afa_markup" class="block text-sm font-medium text-gray-700 mb-2">
                                    Your Markup (GH₵)
                                </label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <span class="text-gray-500 sm:text-sm">GH₵</span>
                                    </div>
                                    <input 
                                        type="number" 
                                        name="afa_markup" 
                                        id="afa_markup"
                                        step="0.01"
                                        min="0"
                                        value="{{ old('afa_markup', $vendor->afa_markup ?? 10) }}"
                                        class="w-full pl-12 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-lg font-semibold"
                                        placeholder="10.00"
                                        oninput="updateResellerPreview()"
                                    >
                                </div>
                                <p class="mt-1 text-sm text-gray-500">
                                    This is the amount you add on top of the base price.
                                </p>
                                @error('afa_markup')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <!-- Reseller Earnings Preview -->
                            <div class="mb-6 bg-gray-50 rounded-lg p-4">
                                <h4 class="text-sm font-medium text-gray-700 mb-2">Reseller Earnings Preview</h4>
                                <div class="space-y-2">
                                    <div class="flex justify-between text-sm">
                                        <span class="text-gray-500">Base Price (Vendor):</span>
                                        <span class="font-medium text-gray-900" id="reseller-base-price">GH₵ {{ number_format($vendor->afa_base_price ?? 0, 2) }}</span>
                                    </div>
                                    <div class="flex justify-between text-sm">
                                        <span class="text-gray-500">Your Markup:</span>
                                        <span class="font-medium text-blue-600" id="reseller-markup">GH₵ {{ number_format($vendor->afa_markup ?? 0, 2) }}</span>
                                    </div>
                                    <div class="flex justify-between text-sm border-t border-gray-200 pt-2">
                                        <span class="text-gray-700 font-medium">Customer Pays:</span>
                                        <span class="font-bold text-gray-900" id="reseller-selling-price">GH₵ {{ number_format($vendor->afa_selling_price ?? 0, 2) }}</span>
                                    </div>
                                    <div class="border-t border-gray-200 pt-2 flex justify-between">
                                        <span class="font-medium text-gray-700">Your Earning:</span>
                                        <span class="font-bold text-blue-600" id="reseller-your-earning">GH₵ {{ number_format(($vendor->afa_markup ?? 0) * 0.98, 2) }}</span>
                                    </div>
                                </div>
                            </div>

                            <button type="submit" class="w-full px-6 py-3 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 transition-colors">
                                Save Reseller Settings
                            </button>
                        </form>

                        @if($vendor->afa_reseller_enabled)
                            <div class="px-6 pb-6">
                                <form method="POST" action="{{ route('vendor.afa.disable-reseller') }}" class="mt-4">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" onclick="return confirm('Are you sure you want to disable reseller mode?')" class="w-full px-6 py-2 bg-red-100 text-red-700 font-medium rounded-lg hover:bg-red-200 transition-colors">
                                        Disable Reseller Mode
                                    </button>
                                </form>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Info Sidebar -->
            <div class="space-y-6">
                <!-- Service Info -->
                <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">About AFA Registration</h3>
                        <div class="space-y-3 text-sm text-gray-600">
                            <p>
                                AFA Registration service allows your customers to submit their details for processing.
                            </p>
                            <p>
                                When a customer submits a registration:
                            </p>
                            <ul class="list-disc list-inside space-y-1 ml-2">
                                <li>They pay the price you set</li>
                                <li>You receive your earning based on your configured price</li>
                                <li>The order appears in your queue for processing</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Your Link -->
                <div class="bg-green-50 rounded-xl border border-green-200 p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">Your AFA Link</h3>
                    <p class="text-sm text-gray-600 mb-4">Share this link with customers to let them register for AFA through your storefront.</p>
                    
                    <div class="bg-white rounded-lg p-3 border border-gray-200">
                        <code class="text-xs text-gray-700 break-all" id="afa-link">
                            {{ url('/store/' . $vendor->vendor_code . '/afa') }}
                        </code>
                    </div>
                    
                    <button 
                        onclick="copyLink()"
                        class="mt-3 w-full px-4 py-2 bg-green-600 text-white font-medium rounded-lg hover:bg-green-700 transition-colors flex items-center justify-center"
                    >
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/>
                        </svg>
                        Copy Link
                    </button>
                </div>

                <!-- Statistics -->
                <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Quick Stats</h3>
                        <div class="space-y-3">
                            <div class="flex justify-between py-2 border-b border-gray-100">
                                <span class="text-gray-500">Total Registrations</span>
                                <span class="font-bold text-gray-900">{{ $vendor->afaRegistrations()->count() }}</span>
                            </div>
                            <div class="flex justify-between py-2 border-b border-gray-100">
                                <span class="text-gray-500">Pending</span>
                                <span class="font-bold text-yellow-600">{{ $vendor->afaRegistrations()->pending()->count() }}</span>
                            </div>
                            <div class="flex justify-between py-2 border-b border-gray-100">
                                <span class="text-gray-500">Completed</span>
                                <span class="font-bold text-green-600">{{ $vendor->afaRegistrations()->where('status', 'completed')->count() }}</span>
                            </div>
                            <div class="flex justify-between py-2">
                                <span class="text-gray-500">Total Earnings</span>
                                <span class="font-bold text-green-600">GH₵ {{ number_format($vendor->afaRegistrations()->where('status', 'completed')->sum('vendor_earning'), 2) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-vendor-layout>

@push('scripts')
<script>
    // Price preview calculator for direct mode
    document.getElementById('afa_price').addEventListener('input', function() {
        const price = parseFloat(this.value) || 0;
        const earning = price * 0.98;

        document.getElementById('preview-customer-pays').textContent = 'GH₵ ' + price.toFixed(2);
        document.getElementById('preview-your-earning').textContent = 'GH₵ ' + earning.toFixed(2);
    });

    // Tab switching
    function switchMode(mode) {
        const directForm = document.getElementById('form-direct');
        const resellerForm = document.getElementById('form-reseller');
        const tabDirect = document.getElementById('tab-direct');
        const tabReseller = document.getElementById('tab-reseller');
        
        if (mode === 'direct') {
            directForm.classList.remove('hidden');
            resellerForm.classList.add('hidden');
            tabDirect.classList.add('border-b-2', 'border-green-500', 'text-green-600', 'bg-green-50');
            tabDirect.classList.remove('text-gray-500', 'hover:text-gray-700', 'hover:bg-gray-50');
            tabReseller.classList.remove('border-b-2', 'border-blue-500', 'text-blue-600', 'bg-blue-50');
            tabReseller.classList.add('text-gray-500', 'hover:text-gray-700', 'hover:bg-gray-50');
        } else {
            directForm.classList.add('hidden');
            resellerForm.classList.remove('hidden');
            tabReseller.classList.add('border-b-2', 'border-blue-500', 'text-blue-600', 'bg-blue-50');
            tabReseller.classList.remove('text-gray-500', 'hover:text-gray-700', 'hover:bg-gray-50');
            tabDirect.classList.remove('border-b-2', 'border-green-500', 'text-green-600', 'bg-green-50');
            tabDirect.classList.add('text-gray-500', 'hover:text-gray-700', 'hover:bg-gray-50');
        }
    }
    
    // Reseller preview calculator
    function updateResellerPreview() {
        const vendorSelect = document.getElementById('afa_source_vendor_id');
        const markupInput = document.getElementById('afa_markup');
        
        const selectedOption = vendorSelect.options[vendorSelect.selectedIndex];
        const basePrice = parseFloat(selectedOption.getAttribute('data-price')) || 0;
        const markup = parseFloat(markupInput.value) || 0;
        
        const sellingPrice = basePrice + markup;
        const yourEarning = markup * 0.98;

        document.getElementById('reseller-base-price').textContent = 'GH₵ ' + basePrice.toFixed(2);
        document.getElementById('reseller-markup').textContent = 'GH₵ ' + markup.toFixed(2);
        document.getElementById('reseller-selling-price').textContent = 'GH₵ ' + sellingPrice.toFixed(2);
        document.getElementById('reseller-your-earning').textContent = 'GH₵ ' + yourEarning.toFixed(2);
    }

    // Copy link function
    function copyLink() {
        const link = document.getElementById('afa-link').textContent.trim();
        navigator.clipboard.writeText(link).then(function() {
            alert('Link copied to clipboard!');
        });
    }
    
    // Initialize reseller preview on page load
    document.addEventListener('DOMContentLoaded', function() {
        updateResellerPreview();
    });
</script>
@endpush
@endsection
