@extends('layouts.vendor')

@section('title', 'Create Product - XTRA4U')

{{--
    Visual redesign only — form action/enctype/@csrf, every field name and
    id, and the #external-services-config data-* attributes the partial
    script below reads are all unchanged. The live preview card and image
    thumbnail are purely decorative (new ids, read-only, no submission
    impact) and degrade to nothing if JS never runs.
--}}
@php
    use Illuminate\Support\Str;
    $mappedExternalServiceId = old('external_service_id', '');
    $mappedExternalServiceName = old('external_service_name', '');
    $mappedExternalServiceNetwork = old('external_service_network', '');
    $mappedExternalServiceCapacity = old('external_service_capacity', '');
    $mappedExternalServicePrice = old('external_service_price', '');
    $mappedExternalServiceOfferSlug = old('external_service_offer_slug', '');
@endphp

@section('content')
<x-vendor-layout :vendor="$vendor" title="Create Product" subtitle="Provide network metadata so your storefront can render rich package cards" active="products">
    <div class="max-w-6xl mx-auto">
        <div class="mb-6">
            <a href="{{ route('vendor.products.index') }}" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-brand-violet transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Back to Products
            </a>
        </div>

        @if ($errors->any())
            <div class="mb-6 flex items-start gap-3 bg-red-50 border border-red-200 rounded-xl px-4 py-3">
                <svg class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                </svg>
                <div>
                    <p class="text-sm font-semibold text-red-800 mb-1">Please correct the following errors:</p>
                    <ul class="text-sm text-red-700 space-y-0.5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">

            {{-- ============================================================
                 Form column
                 ============================================================ --}}
            <form method="POST" action="{{ route('product.store') }}" enctype="multipart/form-data" class="lg:col-span-2 space-y-5">
                @csrf

                {{-- Basic information --}}
                <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center gap-3">
                        <span class="w-9 h-9 rounded-lg bg-violet-50 flex items-center justify-center flex-shrink-0">
                            <svg class="w-5 h-5 text-brand-violet" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10" />
                            </svg>
                        </span>
                        <div>
                            <h2 class="text-sm font-bold text-gray-900">Basic Information</h2>
                            <p class="text-xs text-gray-500">What customers see first on your storefront.</p>
                        </div>
                    </div>

                    <div class="p-6 space-y-4">
                        <div>
                            <label for="name" class="block text-sm font-semibold text-gray-700 mb-1.5">Product Name</label>
                            <input type="text" name="name" id="name" value="{{ old('name') }}" placeholder="e.g. MTN 5GB Data Bundle" class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-violet focus:ring-brand-violet" required>
                        </div>

                        @php
                            $categoryOptions = config('storefront.categories', []);
                            $defaultCategory = config('storefront.default_category') ?? (array_key_first($categoryOptions) ?? 'data');
                        @endphp
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="network" class="block text-sm font-semibold text-gray-700 mb-1.5">Network / Service</label>
                                <select name="network" id="network" class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-violet focus:ring-brand-violet">
                                    <option value="">Choose network / service</option>
                                    @foreach ($networkOptions as $network)
                                        <option value="{{ $network->name }}" {{ old('network') === $network->name ? 'selected' : '' }}>{{ $network->name }} ({{ Str::title(str_replace(['-', '_'], ' ', $network->category)) }})</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="category" class="block text-sm font-semibold text-gray-700 mb-1.5">Category</label>
                                <select name="category" id="category" class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-violet focus:ring-brand-violet">
                                    @foreach ($categoryOptions as $value => $option)
                                        <option value="{{ $value }}" {{ old('category', $defaultCategory) === $value ? 'selected' : '' }}>{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- External fulfillment --}}
                @php($activeExternalFulfillmentProviderLabel = isset($activeExternalFulfillmentProvider) ? Str::headline($activeExternalFulfillmentProvider) : 'External Provider')
                <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center gap-3">
                        <span class="w-9 h-9 rounded-lg bg-emerald-50 flex items-center justify-center flex-shrink-0">
                            <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                        </span>
                        <div>
                            <h2 class="text-sm font-bold text-gray-900">External Fulfillment</h2>
                            <p class="text-xs text-gray-500">Optional — maps this product to an automated provider package{{ isset($activeExternalFulfillmentProvider) ? " ({$activeExternalFulfillmentProviderLabel})" : '' }}.</p>
                        </div>
                    </div>

                    <div class="p-6 space-y-4">
                        <div>
                            <label for="external_network" class="block text-sm font-semibold text-gray-700 mb-1.5">External Fulfillment Network</label>
                            <select name="external_network" id="external_network" class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-violet focus:ring-brand-violet">
                                <option value="" {{ old('external_network') === '' ? 'selected' : '' }}>Auto (recommended)</option>
                                @foreach($providerNetworks as $network)
                                    <option value="{{ $network }}" {{ old('external_network') === $network ? 'selected' : '' }}>{{ $network }}</option>
                                @endforeach
                            </select>
                            <p class="text-xs text-gray-500 mt-1.5">Optional. Used only for External Fulfillment; customers won’t see this.</p>
                        </div>

                        <div>
                            <div class="flex items-center justify-between gap-3">
                                <label for="external_service_id" class="block text-sm font-semibold text-gray-700">Provider Service</label>
                                <button
                                    type="button"
                                    id="external_services_refresh"
                                    class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50"
                                >
                                    Refresh Services
                                </button>
                            </div>
                            <select name="external_service_id" id="external_service_id" class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-violet focus:ring-brand-violet">
                                <option value="">Auto (recommended)</option>
                            </select>
                            <p id="external_services_status" class="text-xs text-gray-400 mt-1.5">Loading services...</p>
                            <p id="external_service_details" class="text-xs text-gray-500 mt-1"></p>

                            <input type="hidden" name="external_service_name" id="external_service_name" value="{{ $mappedExternalServiceName }}">
                            <input type="hidden" name="external_service_network" id="external_service_network" value="{{ $mappedExternalServiceNetwork }}">
                            <input type="hidden" name="external_service_capacity" id="external_service_capacity" value="{{ $mappedExternalServiceCapacity }}">
                            <input type="hidden" name="external_service_price" id="external_service_price" value="{{ $mappedExternalServicePrice }}">
                            <input type="hidden" name="external_service_offer_slug" id="external_service_offer_slug" value="{{ $mappedExternalServiceOfferSlug }}">
                            <p class="text-xs text-gray-500 mt-1.5">Optional. Maps this platform product to a concrete provider package for automated fulfillment.</p>
                        </div>
                    </div>
                </div>
                <div
                    id="external-services-config"
                    class="hidden"
                    data-endpoint="{{ $externalServicesEndpoint ?? '' }}"
                    data-provider="{{ $activeExternalFulfillmentProvider ?? '' }}"
                    data-service-id="{{ $mappedExternalServiceId ?? '' }}"
                    data-service-name="{{ $mappedExternalServiceName ?? '' }}"
                    data-service-network="{{ $mappedExternalServiceNetwork ?? '' }}"
                    data-service-capacity="{{ $mappedExternalServiceCapacity ?? '' }}"
                    data-service-price="{{ $mappedExternalServicePrice ?? '' }}"
                    data-service-offer-slug="{{ $mappedExternalServiceOfferSlug ?? '' }}"
                ></div>

                {{-- Package details --}}
                <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center gap-3">
                        <span class="w-9 h-9 rounded-lg bg-amber-50 flex items-center justify-center flex-shrink-0">
                            <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
                            </svg>
                        </span>
                        <div>
                            <h2 class="text-sm font-bold text-gray-900">Package Details</h2>
                            <p class="text-xs text-gray-500">Size, validity, and pricing shown on the package card.</p>
                        </div>
                    </div>

                    <div class="p-6 space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="size" class="block text-sm font-semibold text-gray-700 mb-1.5">Package Size</label>
                                <input type="text" name="size" id="size" value="{{ old('size') }}" placeholder="1GB, 30 units" class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-violet focus:ring-brand-violet">
                            </div>
                            <div>
                                <label for="validity" class="block text-sm font-semibold text-gray-700 mb-1.5">Validity</label>
                                <input type="text" name="validity" id="validity" value="{{ old('validity') }}" placeholder="Non-expiry, 30 days" class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-violet focus:ring-brand-violet">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="tag" class="block text-sm font-semibold text-gray-700 mb-1.5">Promo Tag</label>
                                <input type="text" name="tag" id="tag" value="{{ old('tag') }}" placeholder="Special Rate" class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-violet focus:ring-brand-violet">
                            </div>
                            <div>
                                <label for="price" class="block text-sm font-semibold text-gray-700 mb-1.5">Price (GHS)</label>
                                <div class="relative">
                                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-sm text-gray-400">₵</span>
                                    <input type="number" name="price" id="price" value="{{ old('price') }}" class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-violet focus:ring-brand-violet pl-7" min="0" step="0.01" required>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Description & media --}}
                <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center gap-3">
                        <span class="w-9 h-9 rounded-lg bg-blue-50 flex items-center justify-center flex-shrink-0">
                            <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 5a2 2 0 012-2h8.586A2 2 0 0116 3.586L19.414 7A2 2 0 0120 8.414V19a2 2 0 01-2 2H6a2 2 0 01-2-2V5z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 13l2 2 4-4" />
                            </svg>
                        </span>
                        <div>
                            <h2 class="text-sm font-bold text-gray-900">Description &amp; Media</h2>
                            <p class="text-xs text-gray-500">Extra context for you and for customers.</p>
                        </div>
                    </div>

                    <div class="p-6 space-y-4">
                        <div>
                            <label for="description" class="block text-sm font-semibold text-gray-700 mb-1.5">Internal Description</label>
                            <textarea name="description" id="description" rows="3" class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-violet focus:ring-brand-violet" placeholder="Optional additional info for your records">{{ old('description') }}</textarea>
                        </div>

                        <div>
                            <label for="notes" class="block text-sm font-semibold text-gray-700 mb-1.5">Customer-facing Notes</label>
                            <textarea name="notes" id="notes" rows="3" class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-violet focus:ring-brand-violet" placeholder="Shown on storefront cards">{{ old('notes') }}</textarea>
                            <p class="mt-1.5 text-xs text-gray-500">These fields will be serialized into JSON so the storefront can render metadata like network, size, validity, and promotions.</p>
                        </div>

                        <div>
                            <label for="image" class="block text-sm font-semibold text-gray-700 mb-1.5">Product Image</label>
                            <div class="flex items-center gap-4">
                                <div id="image_preview_wrap" class="hidden flex-shrink-0">
                                    <img id="image_preview" src="" alt="" class="w-14 h-14 rounded-lg object-cover border border-gray-200">
                                </div>
                                <input type="file" name="image" id="image" accept="image/*" class="block w-full text-sm text-gray-600 rounded-lg border border-gray-300 shadow-sm file:mr-4 file:py-2 file:px-4 file:rounded-l-lg file:border-0 file:text-sm file:font-semibold file:bg-violet-50 file:text-brand-violet-deep hover:file:bg-violet-100">
                            </div>
                            <p class="mt-1.5 text-xs text-gray-500">Optional. JPG, PNG, or GIF up to 2MB.</p>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row gap-3">
                    <button type="submit" class="flex-1 inline-flex items-center justify-center gap-2 bg-brand-violet hover:bg-brand-violet-deep text-white py-3 px-4 rounded-xl font-semibold shadow-sm transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                        </svg>
                        Create Product
                    </button>
                    <a href="{{ route('vendor.products.index') }}" class="inline-flex items-center justify-center px-4 py-3 rounded-xl border border-gray-300 text-gray-700 font-semibold hover:bg-gray-50 transition-colors">
                        Cancel
                    </a>
                </div>
            </form>

            {{-- ============================================================
                 Live preview column (decorative only — reads form fields,
                 submits nothing)
                 ============================================================ --}}
            <div class="lg:col-span-1">
                <div class="lg:sticky lg:top-6 space-y-4">
                    <div class="bg-white rounded-xl border border-gray-200 p-5">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">Storefront Preview</p>

                        <div class="rounded-xl border-2 border-gray-100 p-4" id="product_preview_card">
                            <div class="flex items-start gap-3">
                                <div id="preview_image_wrap" class="hidden flex-shrink-0">
                                    <img id="preview_image" src="" alt="" class="w-11 h-11 rounded-lg object-cover">
                                </div>
                                <div id="preview_icon_wrap" class="w-11 h-11 rounded-lg bg-violet-50 flex items-center justify-center flex-shrink-0">
                                    <svg class="w-5 h-5 text-brand-violet" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 12.55a11 11 0 0114.08 0M1.42 9a16 16 0 0121.16 0M8.53 16.11a6 6 0 016.95 0M12 20h.01" />
                                    </svg>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <p id="preview_name" class="text-sm font-semibold text-gray-900 truncate">Your product name</p>
                                        <span id="preview_tag" class="hidden px-2 py-0.5 text-[11px] font-medium bg-green-100 text-green-700 rounded-full"></span>
                                    </div>
                                    <p id="preview_size" class="text-xs text-gray-500 mt-0.5">Package size appears here</p>
                                </div>
                            </div>
                            <div class="flex items-center justify-between mt-3 pt-3 border-t border-gray-100">
                                <span id="preview_validity" class="text-xs text-green-600"></span>
                                <span id="preview_price" class="text-base font-bold text-brand-violet ml-auto">GHS 0.00</span>
                            </div>
                        </div>

                        <p class="text-xs text-gray-400 mt-3">Roughly how this package will appear to customers on your storefront.</p>
                    </div>

                    <div class="bg-violet-50 border border-violet-100 rounded-2xl p-5">
                        <div class="flex items-start gap-2.5">
                            <svg class="w-5 h-5 text-brand-violet flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <p class="text-xs text-brand-violet-deep leading-relaxed">
                                Package size, validity, and promo tag are optional but recommended — customers use
                                them to compare products at a glance.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-vendor-layout>
@endsection

@push('scripts')
<script>
(function () {
    // Purely decorative live preview + image thumbnail. Reads existing
    // form fields by id and never writes back to them or the form.
    const $ = (id) => document.getElementById(id);
    const nameInput = $('name');
    const priceInput = $('price');
    const sizeInput = $('size');
    const validityInput = $('validity');
    const tagInput = $('tag');
    const imageInput = $('image');

    const previewName = $('preview_name');
    const previewPrice = $('preview_price');
    const previewSize = $('preview_size');
    const previewValidity = $('preview_validity');
    const previewTag = $('preview_tag');
    const previewImage = $('preview_image');
    const previewImageWrap = $('preview_image_wrap');
    const previewIconWrap = $('preview_icon_wrap');
    const imagePreview = $('image_preview');
    const imagePreviewWrap = $('image_preview_wrap');

    function updatePreview() {
        if (previewName) {
            previewName.textContent = (nameInput && nameInput.value.trim()) || 'Your product name';
        }
        if (previewPrice) {
            const price = parseFloat(priceInput && priceInput.value ? priceInput.value : 0) || 0;
            previewPrice.textContent = 'GHS ' + price.toFixed(2);
        }
        if (previewSize) {
            previewSize.textContent = (sizeInput && sizeInput.value.trim()) || 'Package size appears here';
        }
        if (previewValidity) {
            previewValidity.textContent = (validityInput && validityInput.value.trim()) || '';
        }
        if (previewTag) {
            const tagValue = tagInput && tagInput.value.trim();
            previewTag.textContent = tagValue || '';
            previewTag.classList.toggle('hidden', !tagValue);
        }
    }

    [nameInput, priceInput, sizeInput, validityInput, tagInput].forEach((el) => {
        if (el) el.addEventListener('input', updatePreview);
    });
    updatePreview();

    if (imageInput) {
        imageInput.addEventListener('change', function () {
            const file = imageInput.files && imageInput.files[0];
            if (!file) {
                if (imagePreviewWrap) imagePreviewWrap.classList.add('hidden');
                if (previewImageWrap) previewImageWrap.classList.add('hidden');
                if (previewIconWrap) previewIconWrap.classList.remove('hidden');
                return;
            }

            const reader = new FileReader();
            reader.onload = function (e) {
                const src = e.target.result;
                if (imagePreview && imagePreviewWrap) {
                    imagePreview.src = src;
                    imagePreviewWrap.classList.remove('hidden');
                }
                if (previewImage && previewImageWrap && previewIconWrap) {
                    previewImage.src = src;
                    previewImageWrap.classList.remove('hidden');
                    previewIconWrap.classList.add('hidden');
                }
            };
            reader.readAsDataURL(file);
        });
    }
})();
</script>
@endpush

@include('vendor.products.partials.external-services-script')
