@extends('layouts.app')

@section('title', 'Quick Buy - Vendor Storefront')

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
                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'description' => $product->description ?? 'Reliable top-up curated for your customers.',
                        'base_price' => $base,
                        'tag' => $product->tag ?? null,
                        'category' => $product->category ?? 'Wallet Favorites',
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

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10" x-data='quickBuy(@json($catalog), @json($categories))'>
    {{-- Hero section --}}
    <div class="bg-gradient-to-r from-purple-50 via-white to-blue-50 border border-purple-100 rounded-[32px] px-8 py-10 shadow-sm">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-8">
            <div>
                <p class="text-xs uppercase tracking-[0.35em] text-purple-500">Vendor Storefront</p>
                <h1 class="mt-4 text-4xl font-semibold text-gray-900">Shop with {{ $vendor->name }}</h1>
                <div class="inline-flex items-center gap-3 bg-purple-100 text-purple-700 px-4 py-2 rounded-full mt-4 text-sm font-medium">
                    Agent: {{ $vendor->name }} ({{ $vendor->vendor_code ?? 'N/A' }})
                </div>
                <p class="mt-4 text-gray-600">Deliver instant bundles, electricity tokens, results checkers and more directly from your vendor wallet.</p>
            </div>
            <div class="flex flex-col gap-3 min-w-[220px]">
                <div class="rounded-3xl border border-gray-200 bg-white px-5 py-4">
                    <p class="text-xs uppercase tracking-wide text-gray-500">Top-up Balance</p>
                    <p class="text-3xl font-semibold text-gray-900 mt-2">GHS {{ number_format($totalTopups ?? $vendor->wallet_balance, 2) }}</p>
                </div>
                <div class="grid grid-cols-2 gap-3 text-sm">
                    <a href="{{ route('vendor.withdrawals.index', ['tab' => 'topups']) }}" class="inline-flex items-center justify-center rounded-2xl border border-purple-200 text-purple-700 font-semibold py-3">Top Up</a>
                    <a href="{{ route('vendor.withdrawals.index') }}" class="inline-flex items-center justify-center rounded-2xl bg-purple-600 text-white font-semibold py-3">Wallet</a>
                </div>
            </div>
        </div>
    </div>

    {{-- Track order card --}}
    <div class="mt-8" x-data="{ open: false }">
        <button type="button" @click="open = !open" class="w-full flex items-center justify-between bg-purple-50 border border-purple-100 rounded-3xl px-5 py-3 text-sm font-medium text-purple-800">
            <div class="flex items-center gap-3">
                <span class="inline-flex items-center justify-center w-8 h-8 rounded-2xl bg-white text-purple-600">🔔</span>
                <span>Track Your Order</span>
            </div>
            <svg class="w-4 h-4 transition-transform" :class="open ? 'rotate-180' : ''" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.084l3.71-3.854a.75.75 0 111.08 1.04l-4.25 4.417a.75.75 0 01-1.08 0L5.25 8.27a.75.75 0 01-.02-1.06z" clip-rule="evenodd"/></svg>
        </button>
        <div x-show="open" x-collapse x-cloak class="mt-3 bg-white border border-purple-100 rounded-3xl p-5 shadow-sm">
            <p class="text-sm text-gray-600">Need to confirm a client’s delivery? Use the public tracker or visit the Orders tab in your dashboard.</p>
            <div class="mt-4 flex flex-wrap gap-3 text-sm">
                <a href="{{ route('order.status') }}" class="inline-flex items-center gap-2 rounded-2xl bg-purple-600 text-white px-4 py-2 font-medium">Open Tracker →</a>
                <a href="{{ route('vendor.orders.index') }}" class="inline-flex items-center gap-2 rounded-2xl border border-gray-200 px-4 py-2 text-gray-700">Vendor Orders</a>
            </div>
        </div>
    </div>

    {{-- Category selector --}}
    <div class="mt-10 bg-white border border-gray-100 rounded-3xl shadow-sm p-6">
        <p class="text-xs uppercase tracking-wide text-gray-500">Category Selector</p>
        <h2 class="text-2xl font-semibold text-gray-900 mt-1">Choose a category</h2>
        <div class="mt-6 grid grid-cols-1 sm:grid-cols-3 lg:grid-cols-5 gap-4">
            <template x-for="(category, index) in categories" :key="category.value">
                <button type="button" class="w-full rounded-3xl border px-4 py-4 text-left font-medium text-gray-700 transition" :class="index === categoryIndex ? 'bg-purple-50 border-purple-300 text-purple-700 shadow' : 'border-gray-100 hover:border-purple-100 hover:text-purple-600'" @click="selectCategory(index)">
                    <span x-text="category.label"></span>
                    <p class="mt-2 text-xs text-gray-500" x-text="productsCountForCategory(category.value) + ' products'"></p>
                </button>
            </template>
        </div>
    </div>

    {{-- Product + checkout columns --}}
    <div class="mt-10 grid gap-8 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs uppercase tracking-wide text-gray-500">Products</p>
                    <h3 class="text-xl font-semibold text-gray-900" x-text="selectedCategory()?.label"></h3>
                </div>
                <div class="flex items-center gap-4">
                    <p class="text-xs text-gray-500 hidden sm:block">Tap a card to prefill the checkout</p>
                    <button type="button" @click="toggleSort()" class="inline-flex items-center gap-2 rounded-full border px-3 py-2 text-sm text-gray-700 bg-white hover:bg-gray-50">
                        <svg class="w-4 h-4 text-gray-500" viewBox="0 0 20 20" fill="currentColor"><path d="M3 6h14v2H3V6zm2 6h10v2H5v-2z"/></svg>
                        <span x-text="sortDirection === 'asc' ? 'Price: Low → High' : 'Price: High → Low'"></span>
                    </button>
                </div>
            </div>

            <div class="grid sm:grid-cols-2 gap-4">
                <template x-if="filteredProducts().length === 0">
                    <div class="col-span-full text-center text-gray-500 border border-dashed border-gray-200 rounded-3xl py-10">No products yet under this category.</div>
                </template>
                <template x-for="product in filteredProducts()" :key="product.id">
                    <button type="button" class="text-left bg-white border rounded-3xl p-5 shadow-sm hover:shadow-md transition" :class="String(productId) === String(product.id) ? 'border-purple-400 ring-2 ring-purple-200' : 'border-gray-100'" @click="selectProduct(product.id)">
                        <p class="text-xs uppercase tracking-wide text-gray-500" x-text="product.category"></p>
                        <h4 class="text-lg font-semibold text-gray-900 mt-1" x-text="product.name"></h4>
                        <p class="text-sm text-gray-500 mt-2" x-text="product.description"></p>
                        <div class="flex items-center justify-between mt-5 text-sm text-gray-500">
                            <div>
                                <p>Base price</p>
                                <p class="text-xl font-semibold text-purple-600" x-text="formatPrice(product.base_price)"></p>
                            </div>
                            <span class="inline-flex items-center gap-1 text-purple-600 font-medium">
                                Select
                                <svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.293 3.293a1 1 0 011.414 0l4 4a1 1 0 01-1.414 1.414L14 6.414V17a1 1 0 11-2 0V6.414L9.707 8.707A1 1 0 018.293 7.293l4-4z" clip-rule="evenodd"/></svg>
                            </span>
                        </div>
                    </button>
                </template>
            </div>
        </div>

        <aside class="bg-white border border-gray-100 rounded-3xl shadow-sm p-6 sticky top-6">
            <p class="text-xs uppercase tracking-wide text-gray-500">Wallet Checkout</p>
            <h3 class="text-2xl font-semibold text-gray-900 mt-2">Quick Buy order</h3>
            <p class="text-sm text-gray-500 mt-2">Wallet-only purchases debit the base price plus 2% platform fee.</p>

            <dl class="mt-6 space-y-2 bg-gray-50 rounded-3xl p-4 text-sm text-gray-600">
                <div class="flex justify-between">
                    <span>Selected product</span>
                    <span class="font-medium text-gray-900" x-text="selectedProduct()?.name || 'None'">None</span>
                </div>
                <div class="flex justify-between">
                    <span>Base price</span>
                    <span class="font-medium" x-text="formatPrice(selectedBasePrice())">GHS 0.00</span>
                </div>
                <div class="flex justify-between">
                    <span>Wallet balance</span>
                    <span class="font-medium">GHS {{ number_format($vendor->wallet_balance, 2) }}</span>
                </div>
            </dl>

            <form method="POST" action="{{ route('vendor.quick-buy.store') }}" class="mt-6 space-y-4">
                @csrf
                <input type="hidden" name="product_id" :value="productId">
                <input type="hidden" name="payment_method" value="wallet">

                <div>
                    <label class="text-sm font-medium text-gray-700">Recipient phone</label>
                    <input type="tel" name="recipient_phone_number" x-model="recipient" required placeholder="e.g. 0244 123 456" class="mt-2 w-full rounded-2xl border-gray-200 focus:ring-2 focus:ring-purple-500" />
                </div>

                <div>
                    <label class="text-sm font-medium text-gray-700">Payer MoMo number</label>
                    <input type="tel" name="mobile_money_number" x-model="momo" required placeholder="Same or alternate MoMo" class="mt-2 w-full rounded-2xl border-gray-200 focus:ring-2 focus:ring-purple-500" />
                </div>

                <div class="rounded-2xl border border-dashed border-amber-300 bg-amber-50 text-amber-800 text-sm p-4">
                    <p class="font-semibold">Wallet-only purchases</p>
                    <p class="mt-1">Ensure your wallet top-ups cover the base price before placing the order.</p>
                    <p class="mt-2 text-xs text-amber-700" x-show="selectedBasePrice() > vendorBalance">Balance too low. Add funds in Wallet → Top-Ups.</p>
                </div>

                <button type="submit" class="w-full rounded-2xl bg-purple-600 text-white font-semibold py-3 hover:bg-purple-700 disabled:opacity-60 disabled:cursor-not-allowed" :disabled="!productId || selectedBasePrice() > vendorBalance">
                    <span x-text="productId ? 'Place wallet order' : 'Select a product'">Select a product</span>
                </button>
            </form>

            <p class="mt-6 text-xs text-gray-500">Orders are fulfilled instantly. For escalations call {{ $vendor->support_phone ?? '+233 XX XXX XXXX' }}.</p>
        </aside>
    </div>

    <div class="mt-10 flex items-center justify-between text-xs text-gray-400">
        <a href="{{ route('vendor.dashboard') }}" class="inline-flex items-center gap-2 text-purple-600 font-medium">
            <svg class="w-3 h-3" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.293 15.707a1 1 0 010-1.414L15.586 11H4a1 1 0 110-2h11.586l-3.293-3.293a1 1 0 111.414-1.414l5 5a1 1 0 010 1.414l-5 5a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
            Back to dashboard
        </a>
        <span>XTRA4U Vendor Experience</span>
    </div>
</div>

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
        // flatten products for convenience (supports older browsers without flatMap)
        products: (catalog || []).flatMap ? (catalog || []).flatMap(c => c.products || []) : (catalog || []).reduce((acc, c) => acc.concat(c.products || []), []),
        vendorBalance: {{ json_encode((float) ($totalTopups ?? $vendor->wallet_balance)) }},
        recipient: '',
        momo: '',

        toggleSort() {
            this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc';
        },

        selectCategory(index) {
            this.categoryIndex = index;
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

        selectProduct(id) {
            this.productId = id;
        },

        selectedProduct() {
            if (!this.productId) return null;
            return (this.products || []).find(pr => String(pr.id) === String(this.productId)) || null;
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

        formatPrice(v) {
            return 'GHS ' + parseFloat(v || 0).toFixed(2);
        }
    }));
});
</script>

@endsection
