@extends('layouts.vendor')

@section('title', 'Quick Buy - XTRA4U')

{{--
    Visual redesign only — the entire quickBuy() Alpine component (all
    three <script> blocks below, and its x-data='quickBuy(@json(...))'
    attribute, matched elsewhere by `[x-data^="quickBuy("]`), the purchase
    form's action/hidden inputs/field names, and every route() call are
    byte-for-byte unchanged. Now wrapped in <x-vendor-layout> so this page
    shares the sidebar/topbar with the rest of the vendor dashboard instead
    of rendering as a standalone storefront-style page — $vendor was
    already passed by the controller, so no backend change was needed.
--}}
@section('content')
@php
    // group products by their category for the quick buy UI
    $defaultCategory = config('storefront.default_category', 'data');
    $catalog = $products
        ->groupBy(fn ($product) => $product->category ?? $defaultCategory)
        ->map(function ($group, $category) {
            return [
                'name' => $category,
                'products' => $group->map(function ($product) {
                    $base = (float) ($product->base_price ?? $product->min_base_price ?? $product->price);
                    $size = $product->package_size ?? data_get($product, 'decoded_description.size');
                    $validity = $product->validity ?? data_get($product, 'decoded_description.validity');
                    $network = $product->network ?? data_get($product, 'decoded_description.network');
                    $tag = $product->tag ?? data_get($product, 'decoded_description.tag');
                    $categoryLabel = $product->category ?? data_get($product, 'decoded_description.category') ?? 'Wallet Favorites';
                    $displayDescription = $product->display_description ?? $product->description ?? 'Reliable top-up curated for your customers.';
                    $logo = $product->network_logo ?? null;
                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'description' => $displayDescription,
                        'display_description' => $displayDescription,
                        'base_price' => $base,
                        'size' => is_string($size) ? trim($size) : null,
                        'validity' => is_string($validity) ? trim($validity) : null,
                        'network' => is_string($network) ? trim($network) : null,
                        'tag' => is_string($tag) ? trim($tag) : null,
                        'category' => is_string($categoryLabel) ? trim($categoryLabel) : 'Wallet Favorites',
                        'logo' => $logo,
                        'is_afa' => $product->is_afa ?? false,
                        'afa_url' => $product->afa_url ?? null,
                    ];
                })->values(),
            ];
        })->values();

    // Build a global category list from config and include any vendor-only categories
    $globalConfig = config('storefront.categories', []);
    $globalCategories = collect($globalConfig)->map(function ($meta, $key) {
        return [
            'id' => $key,
            'value' => $key,
            'label' => $meta['label'] ?? 
                
                ucwords(str_replace(['-', '_'], ' ', $key)),
            'description' => $meta['description'] ?? null,
            'serviceCount' => 0,
        ];
    })->values();

    $vendorCategoryKeys = $catalog->pluck('name')->unique()->reject(fn ($k) => isset($globalConfig[$k]));
    $vendorExtras = $vendorCategoryKeys->map(function ($key) {
        return [
            'id' => $key,
            'value' => $key,
            'label' => ucwords(str_replace(['-', '_'], ' ', $key)),
            'description' => null,
            'serviceCount' => 0,
        ];
    });

    $categories = $globalCategories->concat($vendorExtras)->values();
@endphp

<x-vendor-layout :vendor="$vendor" title="Quick Buy" subtitle="Fast wallet-only orders — base price + 2% platform fee" active="dashboard">
<div class="w-full" x-data='quickBuy(@json($catalog), @json($categories))'>
    {{-- Header: wallet balance --}}
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5 flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-4">
        <div class="flex-1">
            <p class="text-xs font-semibold text-brand-violet uppercase tracking-wide">Quick Buy (Wallet)</p>
            <p class="text-sm text-gray-500 mt-1">Internal vendor flow — not shown to customers.</p>
        </div>

        <div class="flex-shrink-0 w-full md:w-auto">
            <div class="w-full sm:max-w-xs">
                <div class="rounded-xl px-4 py-3 bg-gray-50 border border-gray-100">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <p class="text-xs text-gray-500">Available Wallet Balance</p>
                            <p class="text-2xl font-bold mt-1 transition-colors duration-200" :class="balanceColorClass()" x-text="formatPrice(vendorBalance)">GHS 0.00</p>
                            <p class="text-xs text-gray-400 mt-1">Used only for wallet orders</p>
                        </div>
                        <div class="text-right">
                            <p class="text-xs text-gray-400">Status</p>
                            <div class="mt-1 text-sm font-semibold" :class="balanceColorClass(true)"> <span x-text="balanceLabel()">—</span></div>
                        </div>
                    </div>
                </div>
                <div class="mt-3 flex items-center justify-end gap-3">
                    <a href="{{ route('vendor.withdrawals.index', ['tab' => 'topups']) }}" class="text-sm font-semibold text-brand-violet topup-open">Top up wallet</a>
                </div>
            </div>
        </div>
    </div>

    {{-- Track order card --}}
    <div class="mb-4" x-data="{ open: false }">
        <button type="button" @click="open = !open" class="w-full flex items-center justify-between bg-violet-50 border border-violet-100 rounded-xl px-5 py-3 text-sm font-medium text-brand-violet-deep">
            <div class="flex items-center gap-3">
                <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-white text-brand-violet">🔔</span>
                <span>Track Your Order</span>
            </div>
            <svg class="w-4 h-4 transition-transform" :class="open ? 'rotate-180' : ''" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.084l3.71-3.854a.75.75 0 111.08 1.04l-4.25 4.417a.75.75 0 01-1.08 0L5.25 8.27a.75.75 0 01-.02-1.06z" clip-rule="evenodd"/></svg>
        </button>
        <div x-show="open" x-collapse x-cloak class="mt-3 bg-white border border-gray-100 rounded-xl p-5 shadow-sm">
            <p class="text-sm text-gray-600">Need to confirm a client’s delivery? Use the public tracker or visit the Orders tab in your dashboard.</p>
            <div class="mt-4 flex flex-wrap gap-3 text-sm">
                <a href="{{ route('order.status') }}" class="inline-flex items-center gap-2 rounded-lg bg-brand-violet text-white px-4 py-2 font-medium hover:bg-brand-violet-deep transition-colors">Open Tracker →</a>
                <a href="{{ route('vendor.orders.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-4 py-2 text-gray-700 hover:bg-gray-50 transition-colors">Vendor Orders</a>
            </div>
        </div>
    </div>

    {{-- Category selector: compact mobile scroller and full desktop grid --}}
    <div class="mb-4 block lg:hidden">
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
            <div class="overflow-x-auto no-scrollbar -mx-3 px-3">
                <div class="flex gap-3 items-center">
                    <template x-for="(category, index) in categories" :key="category.value">
                        <button type="button" @click="selectCategory(index)" :class="index === categoryIndex ? 'bg-violet-50 border-violet-300 text-brand-violet-deep' : 'bg-white border-gray-100 text-gray-700'" class="flex-shrink-0 rounded-full border px-5 py-2.5 text-sm font-semibold whitespace-nowrap min-h-[44px]">
                            <span x-text="category.label"></span>
                        </button>
                    </template>
                </div>
            </div>
        </div>
    </div>

    {{-- full grid for lg+ screens --}}
    <div class="mb-4 hidden lg:block bg-white border border-gray-100 rounded-xl shadow-sm p-6">
        <p class="text-xs uppercase tracking-wide text-gray-500">Category Selector</p>
        <h2 class="text-xl font-bold text-gray-900 mt-1">Choose a category</h2>
        <div class="mt-6 grid grid-cols-1 sm:grid-cols-3 lg:grid-cols-5 gap-4">
            <template x-for="(category, index) in categories" :key="category.value">
                <button type="button" class="w-full rounded-xl border px-4 py-4 text-left font-medium text-gray-700 transition" :class="index === categoryIndex ? 'bg-violet-50 border-violet-300 text-brand-violet-deep shadow-sm' : 'border-gray-100 hover:border-violet-100 hover:text-brand-violet'" @click="selectCategory(index)">
                    <span x-text="category.label"></span>
                    <p class="mt-2 text-xs text-gray-400">&nbsp;</p>
                </button>
            </template>
        </div>
    </div>

    {{-- Product + checkout columns --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-4 w-full">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs uppercase tracking-wide text-gray-500">Products</p>
                    <h3 class="text-xl font-bold text-gray-900" x-text="selectedCategory()?.label"></h3>
                    <p class="text-xs text-gray-500 mt-1" x-show="selectedCategory()">Choose a service first to see matching products.</p>
                </div>
                <div class="flex items-center gap-4">
                    <p class="text-xs text-gray-500 hidden sm:block">Tap a card to prefill the checkout</p>
                </div>
            </div>

            {{-- Service chooser --}}
            <div class="bg-white border border-gray-100 rounded-xl shadow-sm p-4" x-show="selectedCategory()" x-cloak>
                <p class="text-xs uppercase tracking-wide text-gray-500">Choose Service</p>
                <div class="mt-3 space-y-3">
                    <template x-for="service in servicesForSelectedCategory()" :key="service.value">
                        <button type="button" @click="selectService(service.value)" :class="serviceSelected(service.value) ? 'border-violet-400 bg-violet-50 text-brand-violet-deep shadow-sm' : 'border-gray-200 bg-white text-gray-800 hover:border-violet-100'" class="w-full rounded-xl border px-4 py-3 text-left font-medium flex items-center gap-3">
                            <img :src="service.logo || '/images/default-provider.png'" alt="" class="w-12 h-12 rounded-md object-cover">
                            <span class="text-lg" x-text="service.label"></span>
                        </button>
                    </template>
                    <template x-if="servicesForSelectedCategory().length === 0">
                        <div class="text-sm text-gray-500">No services available for this category.</div>
                    </template>
                </div>
            </div>

            <div class="space-y-3">
                <template x-if="!serviceFilter">
                    <div class="text-center text-gray-500 border border-dashed border-gray-200 rounded-xl py-10">Select a service to view products.</div>
                </template>
                <template x-if="serviceFilter && filteredProducts().length === 0">
                    <div class="text-center text-gray-500 border border-dashed border-gray-200 rounded-xl py-10">No products yet under this service.</div>
                </template>

                <template x-if="packageLocked && selectedProduct()">
                    <div class="flex flex-col gap-3">
                        <div class="flex items-center justify-between">
                            <h4 class="text-sm font-semibold text-gray-700">Selected package</h4>
                            <button type="button" class="text-sm font-medium text-brand-violet-deep hover:text-brand-violet-deep" @click="unlockPackageSelection()">Change package</button>
                        </div>
                        <div>
                            <button type="button" role="button" aria-pressed="true" class="w-full text-left bg-violet-50 border border-violet-300 rounded-xl p-5 shadow-sm flex flex-col gap-3 focus:outline-none focus:ring-4 focus:ring-violet-100">
                                <div class="flex items-start gap-4">
                                    <img :src="selectedProduct().logo || '/images/default-provider.png'" alt="" class="w-12 h-12 rounded-md object-cover flex-shrink-0">
                                    <div class="flex-1 flex justify-between items-start">
                                        <div class="flex-1">
                                            <div class="flex items-center gap-2">
                                                <div class="font-semibold text-gray-900" x-text="selectedProduct().name"></div>
                                                <span class="px-2 py-0.5 text-xs font-medium bg-green-100 text-green-700 rounded-full" x-show="selectedProduct().tag" x-text="selectedProduct().tag"></span>
                                            </div>
                                            <div class="text-sm text-gray-500 mt-1" x-text="sizeLabel(selectedProduct())"></div>
                                        </div>
                                        <div class="text-right ml-4">
                                            <div class="text-lg font-bold text-brand-violet" x-text="formatPrice(selectedProduct().base_price)"></div>
                                            <div class="text-sm text-green-600 mt-1" x-text="selectedProduct().validity || ''"></div>
                                        </div>
                                    </div>
                                </div>
                            </button>
                        </div>
                    </div>
                </template>

                <template x-if="!packageLocked">
                    <div class="space-y-3">
                        <template x-for="product in filteredProducts()" :key="product.id">
                            <div>
                                <button type="button" role="button" :aria-pressed="String(productId) === String(product.id) ? 'true' : 'false'" class="w-full text-left bg-white border rounded-xl p-5 shadow-sm hover:shadow-md transition flex flex-col gap-3 focus:outline-none focus:ring-4 focus:ring-violet-100" :class="String(productId) === String(product.id) ? 'border-violet-400 ring-2 ring-violet-200 bg-violet-50' : 'border-gray-100'" @click="selectProduct(product.id)">
                                    <div class="flex items-start gap-4">
                                        <img :src="product.logo || '/images/default-provider.png'" alt="" class="w-12 h-12 rounded-md object-cover flex-shrink-0">
                                        <div class="flex-1 flex justify-between items-start">
                                            <div class="flex-1">
                                                <div class="flex items-center gap-2">
                                                    <div class="font-semibold text-gray-900" x-text="product.name"></div>
                                                    <span class="px-2 py-0.5 text-xs font-medium bg-green-100 text-green-700 rounded-full" x-show="product.tag" x-text="product.tag"></span>
                                                </div>
                                                <div class="text-sm text-gray-500 mt-1" x-text="sizeLabel(product)"></div>
                                            </div>
                                            <div class="text-right ml-4">
                                                <div class="text-lg font-bold text-brand-violet" x-text="formatPrice(product.base_price)"></div>
                                                <div class="text-sm text-green-600 mt-1" x-text="product.validity || ''"></div>
                                            </div>
                                        </div>
                                    </div>
                                </button>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </div>

        <aside x-cloak x-show="productId" x-transition.opacity.duration.200ms class="bg-white border border-gray-100 rounded-xl shadow-sm p-6 order-last lg:order-none w-full lg:sticky lg:top-20">
            <p class="text-xs uppercase tracking-wide text-gray-500">Wallet Checkout</p>
            <h3 class="text-xl font-bold text-gray-900 mt-2">Quick Buy order</h3>
            <p class="text-sm text-gray-500 mt-2">Wallet-only purchases debit the base price plus 2% platform fee.</p>

            <div class="mt-6 bg-gray-50 rounded-xl p-4 text-sm text-gray-700">
                <div class="grid grid-cols-2 gap-2 items-center">
                    <div>
                        <p class="text-xs text-gray-500">Selected product</p>
                        <p class="font-medium text-gray-900" x-text="selectedProductLabel()">None</p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-gray-500">Base</p>
                        <p class="font-semibold text-brand-violet" x-text="formatPrice(selectedBasePrice())">GHS 0.00</p>
                    </div>
                </div>

                <div class="mt-3 grid grid-cols-2">
                    <div class="text-sm text-gray-500">Platform fee (2%)</div>
                    <div class="text-right font-medium" x-text="formatPrice((selectedBasePrice() * 0.02).toFixed(2))">GHS 0.00</div>
                </div>

                <div class="mt-3 border-t pt-3 grid grid-cols-2 items-center">
                    <div class="text-sm font-medium">Total wallet charge</div>
                    <div class="text-right text-lg font-bold text-gray-900" x-text="formatPrice(selectedTotalCharge())">GHS 0.00</div>
                </div>

                <p class="mt-2 text-xs text-gray-500">Wallet orders do not generate earnings for the buyer.</p>
            </div>

                <form method="POST" action="{{ route('vendor.quick-buy.store') }}" class="mt-6 space-y-4" x-on:submit="submitting = true">
                @csrf
                <input type="hidden" name="product_id" :value="productId">
                <input type="hidden" name="payment_method" x-model="paymentMethod">

                <div>
                    <label for="recipient_phone" class="text-sm font-medium text-gray-700">Recipient phone</label>
                    <input id="recipient_phone" aria-label="Recipient phone number" type="tel" name="recipient_phone_number" x-model="recipient" required placeholder="e.g. 0244 123 456" class="mt-2 w-full rounded-2xl border border-gray-200 focus:ring-2 focus:ring-violet-500 px-3 py-3 text-sm" />
                    <p class="mt-2 text-xs text-gray-500">This is the number that will receive the bundle.</p>
                </div>

                <div x-cloak x-show="paymentMethod !== 'wallet'" x-transition>
                    <label class="text-sm font-medium text-gray-700">Payer MoMo number</label>
                    <input type="tel" name="mobile_money_number" x-model="momo" placeholder="Same or alternate MoMo" class="mt-2 w-full rounded-2xl border border-gray-200 focus:ring-2 focus:ring-violet-500 px-3 py-3 text-sm" />
                </div>

                <div class="flex gap-2 items-center">
                    <label class="text-sm font-medium text-gray-700 mr-2">Payment</label>
                    <div class="flex gap-2">
                        <button type="button" @click.prevent="paymentMethod='wallet'" :class="paymentMethod==='wallet' ? 'bg-brand-violet text-white' : 'bg-white text-gray-700 border'" class="px-3 py-2 rounded-2xl border">Wallet</button>                    </div>
                </div>

                <div class="rounded-2xl border border-blue-100 bg-blue-50 text-blue-800 text-sm p-4">
                    <div class="flex items-start gap-3">
                        <svg class="w-5 h-5 text-blue-600 mt-0.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 2a4 4 0 00-4 4v2H5a1 1 0 000 2h10a1 1 0 000-2h-1V6a4 4 0 00-4-4zM7 10a3 3 0 016 0v2H7v-2z"/></svg>
                        <div>
                            <p class="font-semibold text-gray-900">🔒 Wallet-only purchase</p>
                            <p class="mt-1 text-gray-700">Uses wallet top-ups only. Top-ups are not withdrawable. Platform fee is charged upfront.</p>
                            <p class="mt-1 text-xs text-blue-700" x-show="selectedTotalCharge() > vendorBalance">Balance insufficient for this product. Add top-ups.</p>
                        </div>
                    </div>
                </div>

                <button type="submit" class="w-full rounded-2xl bg-brand-violet text-white font-semibold py-4 hover:bg-brand-violet-deep disabled:opacity-60 disabled:cursor-not-allowed flex items-center justify-center gap-3 text-lg" :disabled="!canPlaceOrder() || submitting" aria-disabled="{{ 'false' }}">
                    <svg x-show="submitting" class="w-5 h-5 animate-spin text-white" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity="0.2" stroke-width="4"/><path d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="4" stroke-linecap="round"/></svg>
                    <span x-text="submitting ? 'Processing…' : (productId ? (paymentMethod === 'wallet' ? 'Place wallet order' : 'Proceed to payment') : 'Select a product')">Select a product</span>
                </button>
                <div class="mt-2 text-sm" x-show="paymentMethod === 'wallet' && selectedTotalCharge() > vendorBalance">
                    <span class="text-red-600">Insufficient balance.</span>
                    <a href="{{ route('vendor.withdrawals.index', ['tab' => 'topups']) }}" class="text-brand-violet font-semibold ml-2">Top up wallet</a>
                </div>
            </form>
        </aside>
    </div>
</div>
</x-vendor-layout>

<script>
document.addEventListener('alpine:init', () => {
    // Accept the server-passed catalog and categories when `x-data="quickBuy(@json($catalog), @json($categories))"` is used
    Alpine.data('quickBuy', (catalog = [], categories = []) => ({
        productId: null,
        // make the catalog available in the component
        catalog: catalog || [],
        // make the categories available in the component
        categories: categories || [],
        // price sort direction: 'asc' or 'desc'
        sortDirection: 'asc',
        // currently selected category index (used by the template)
        categoryIndex: 0,
        // selected service/network within the chosen category
        serviceFilter: null,
        // when true, hide other packages after one is chosen
        packageLocked: false,
        // flatten products for convenience (supports older browsers without flatMap)
        products: (catalog || []).flatMap ? (catalog || []).flatMap(c => c.products || []) : (catalog || []).reduce((acc, c) => acc.concat(c.products || []), []),
        vendorBalance: {{ json_encode((float) ($totalTopups ?? $vendor->wallet_balance)) }},
        // current payment method for the quick-buy form. Default to wallet.
        paymentMethod: 'wallet',
        recipient: '',
        momo: '',
        // UI state
        submitting: false,

        init() {
            this.autoSelectDefaultService();
        },

        toggleSort() {
            this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc';
        },

        selectCategory(index) {
            this.categoryIndex = index;
            this.productId = null;
            this.packageLocked = false;
            this.autoSelectDefaultService();
        },

        selectedCategory() {
            return (this.categories && typeof this.categoryIndex === 'number') ? (this.categories[this.categoryIndex] || null) : null;
        },

        productsCountForCategory(value) {
            const g = (this.catalog || []).find(c => String(c.name) === String(value));
            if (g && Array.isArray(g.products)) return g.products.length;
            const cat = (this.categories || []).find(c => String(c.value) === String(value));
            return (cat && cat.serviceCount) ? cat.serviceCount : 0;
        },

        servicesForSelectedCategory() {
            const sel = this.selectedCategory();
            if (!sel) return [];
            const group = (this.catalog || []).find(c => String(c.name) === String(sel.value));
            const products = (group && Array.isArray(group.products)) ? group.products : [];

            const byService = {};
            products.forEach(p => {
                const key = (p.network && String(p.network).trim()) ? String(p.network).trim() : 'Other';
                if (!byService[key]) {
                    byService[key] = { value: key, label: key, count: 0, logo: p.logo || null, is_afa: p.is_afa, afa_url: p.afa_url };
                }
                byService[key].count += 1;
                if (!byService[key].logo && p.logo) {
                    byService[key].logo = p.logo;
                }
            });

            return Object.values(byService);
        },

        selectService(value) {
            const services = this.servicesForSelectedCategory();
            const svc = services.find(s => String(s.value) === String(value));
            if (svc && svc.is_afa && svc.afa_url) {
                window.location.href = svc.afa_url;
                return;
            }
            this.serviceFilter = value;
            this.productId = null;
            this.packageLocked = false;
        },

        serviceSelected(value) {
            return String(this.serviceFilter) === String(value);
        },

        unlockPackageSelection() {
            this.packageLocked = false;
            this.productId = null;
        },

        autoSelectDefaultService() {
            const services = this.servicesForSelectedCategory();
            const mtn = services.find(s => String(s.label || s.value || '').toLowerCase() === 'mtn');
            this.serviceFilter = mtn ? mtn.value : null;
            this.productId = null;
            this.packageLocked = false;
        },

        selectProduct(id) {
            const prod = (this.products || []).find(pr => String(pr.id) === String(id));
            if (prod && prod.is_afa && prod.afa_url) {
                window.location.href = prod.afa_url;
                return;
            }
            this.productId = id;
            this.packageLocked = true;
        },

        selectedProduct() {
            if (!this.productId) return null;
            return (this.products || []).find(pr => String(pr.id) === String(this.productId)) || null;
        },

        selectedProductLabel() {
            const prod = this.selectedProduct();
            if (!prod) return 'None';
            const size = this.sizeLabel(prod);
            return size ? `${prod.name} — ${size}` : prod.name;
        },

        filteredProducts() {
            const sel = this.selectedCategory();
            let list = [];
            if (sel && sel.value) {
                const group = (this.catalog || []).find(c => String(c.name) === String(sel.value));
                list = (group && group.products) ? (group.products.slice ? group.products.slice() : [].concat(group.products)) : [];
            } else {
                list = (this.products || []).slice ? this.products.slice() : [].concat(this.products || []);
            }

            if (this.serviceFilter) {
                list = list.filter(p => {
                    const net = p.network || (p.decoded_description ? p.decoded_description.network : null);
                    const label = (net && String(net).trim()) ? String(net).trim() : 'Other';
                    return String(label) === String(this.serviceFilter);
                });
            } else {
                // hide products until a service is chosen
                return [];
            }

            // sort by base_price according to sortDirection
            list.sort((a, b) => {
                const pa = parseFloat(a.base_price ?? a.min_base_price ?? a.price ?? 0);
                const pb = parseFloat(b.base_price ?? b.min_base_price ?? b.price ?? 0);
                return this.sortDirection === 'asc' ? (pa - pb) : (pb - pa);
            });

            return list;
        },

        selectedBasePrice() {
            const prod = this.selectedProduct();
            if (!prod) return 0;
            return parseFloat(prod.base_price ?? prod.min_base_price ?? prod.price ?? 0);
        },

        selectedTotalCharge() {
            const base = this.selectedBasePrice();
            const fee = base * 0.02;
            return parseFloat((base + fee).toFixed(2));
        },

        // return a human label for balance vs required amount
        balanceLabel() {
            if (!this.productId) return '—';
            const need = this.selectedTotalCharge();
            if (this.vendorBalance >= need) {
                // if remaining balance after order is small, mark as Low
                const remaining = this.vendorBalance - need;
                if (remaining <= Math.max(0.01, need * 0.2)) return 'Low';
                return 'Sufficient';
            }
            return 'Insufficient';
        },

        // return css class based on balance status. If textOnly, return text color class
        balanceColorClass(textOnly = false) {
            const label = this.balanceLabel();
            if (label === 'Sufficient') return textOnly ? 'text-green-600' : 'text-green-600';
            if (label === 'Low') return textOnly ? 'text-amber-600' : 'text-amber-600';
            if (label === 'Insufficient') return textOnly ? 'text-red-600' : 'text-red-600';
            return textOnly ? 'text-gray-600' : 'text-gray-600';
        },

        // whether the form can be submitted
        canPlaceOrder() {
            if (!this.productId) return false;
            if (!this.recipient || String(this.recipient).trim().length < 6) return false;
            if (this.paymentMethod === 'wallet' && this.selectedTotalCharge() > this.vendorBalance) return false;
            return true;
        },

        formatPrice(v) {
            return 'GHS ' + parseFloat(v || 0).toFixed(2);
        }

        ,

        sizeLabel(prod) {
            if (!prod) return '';
            const value = prod.size || prod.package_size || (prod.decoded_description ? prod.decoded_description.size : null);
            return (typeof value === 'string' && value.trim().length > 0) ? value.trim() : '';
        },

        // Safely derive a readable description from product data which may be
        // a string, a JSON-encoded string, or an object. Returns an empty
        // string when no friendly text can be found.
        displayDescription(prod) {
            if (!prod) return '';
            let d = prod.display_description || prod.description || '';

            // If the value is already an object, attempt to pick a sensible field.
            if (d && typeof d === 'object') {
                return d.short_description || d.description || d.notes || '';
            }

            // If it's a string, try to detect JSON and parse.
            if (typeof d === 'string') {
                const trimmed = d.trim();
                if ((trimmed.startsWith('{') && trimmed.endsWith('}')) || (trimmed.startsWith('[') && trimmed.endsWith(']'))) {
                    try {
                        const parsed = JSON.parse(trimmed);
                        if (parsed && typeof parsed === 'object') {
                            return parsed.short_description || parsed.description || parsed.notes || '';
                        }
                    } catch (e) {
                        // not JSON — fall through to return raw string
                    }
                }

                // Fallback: return the raw string but truncated if very long
                if (trimmed.length > 220) return trimmed.slice(0, 217) + '...';
                return trimmed;
            }

            return '';
        },
    }));
});
</script>

<script>
// Quick-buy: open the same modal used on Withdrawals page when clicking Top up
document.addEventListener('DOMContentLoaded', function () {
    const links = document.querySelectorAll('a.topup-open');
    links.forEach(a => {
        a.addEventListener('click', function (ev) {
            ev.preventDefault();
            // If the withdrawals page modal exists on this page, open it; otherwise navigate
            const modal = document.getElementById('wallet-topup-modal');
            if (modal && typeof modal.classList !== 'undefined') {
                modal.classList.remove('hidden');
                modal.classList.add('flex');
                // Try to prefill amount if quick-buy knows a reasonable default (none here)
            } else {
                window.location.href = a.href;
            }
        });
    });
});
</script>

<script>
// Poll vendor balance every 30 seconds to keep UI fresh
(function () {
    const pollInterval = 30000;
    async function refreshBalance() {
        try {
            const resp = await fetch("{{ route('vendor.wallet.balance') }}", {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            });

            // If the server redirected to login or returned unauthorized, skip update
            if (resp.status === 401 || resp.status === 302) return;

            if (!resp.ok) {
                console.warn('Failed to refresh vendor balance, status:', resp.status);
                return;
            }

            // Ensure we have JSON before parsing
            const contentType = resp.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) return;

            const data = await resp.json();
            if (data.success && window.Alpine) {
                // find the quickBuy Alpine component and update vendorBalance
                const el = document.querySelector('[x-data^="quickBuy("]');
                if (el && el.__x) {
                    try { el.__x.$data.vendorBalance = parseFloat(data.vendor_topups_total || data.wallet_balance || 0); } catch (e) {}
                }
            }
        } catch (e) {
            console.warn('refreshBalance error', e);
        }
    }

    setInterval(refreshBalance, pollInterval);
    // initial refresh shortly after load
    setTimeout(refreshBalance, 2000);
})();
</script>

@endsection
