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
    <div class="sticky top-0 z-30 bg-white/80 backdrop-blur-sm border-b border-gray-100">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 md:py-6">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <p class="text-xs uppercase tracking-[0.35em] text-purple-500">Quick Buy (Wallet)</p>
                    <h1 class="mt-1 text-xl sm:text-2xl font-semibold text-gray-900">Fast wallet-only orders</h1>
                    <p class="text-xs text-gray-500 mt-1">Base price + 2% platform fee • Internal vendor flow</p>
                </div>

                <div class="flex items-center gap-4">
                    <div x-data class="hidden sm:block">
                        <a href="{{ route('vendor.withdrawals.index', ['tab' => 'topups']) }}" class="inline-flex items-center gap-2 rounded-full border border-purple-200 text-purple-700 font-semibold px-4 py-2">Top Up</a>
                    </div>

                    <div x-data="{ }" class="min-w-[180px]">
                        <div x-data="{ }" x-init="$el.closest('[x-data]').__balance = true">
                            <div class="rounded-2xl px-4 py-3 flex items-center justify-between shadow-sm bg-white">
                                <div>
                                    <p class="text-xs text-gray-500">Available Wallet Balance</p>
                                    <p class="text-lg font-semibold mt-1 transition-colors duration-200" :class="balanceColorClass()" x-text="formatPrice(vendorBalance)">GHS 0.00</p>
                                </div>
                                <div class="text-right">
                                    <p class="text-xs text-gray-400">Status</p>
                                    <div class="mt-1 text-sm font-medium" :class="balanceColorClass(true)"> <span x-text="balanceLabel()">—</span></div>
                                </div>
                            </div>
                        </div>
                    </div>
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
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-2 gap-4">
                <template x-if="filteredProducts().length === 0">
                    <div class="col-span-full text-center text-gray-500 border border-dashed border-gray-200 rounded-3xl py-10">No products yet under this category.</div>
                </template>
                <template x-for="product in filteredProducts()" :key="product.id">
                    <div class="relative">
                        <button type="button" class="w-full text-left bg-white border rounded-3xl p-5 shadow-sm hover:shadow-md transition flex flex-col h-full justify-between" :class="String(productId) === String(product.id) ? 'border-purple-400 ring-2 ring-purple-200' : 'border-gray-100'" @click="selectProduct(product.id)">
                            <div>
                                <div class="flex items-start justify-between">
                                    <div>
                                        <p class="text-xs uppercase tracking-wide text-gray-500 truncate" x-text="product.category"></p>
                                        <h4 class="text-lg font-semibold text-gray-900 mt-1" x-text="product.name"></h4>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-xs text-gray-400">Base</p>
                                        <p class="text-xl font-semibold text-purple-600" x-text="formatPrice(product.base_price)"></p>
                                    </div>
                                </div>

                                <p class="text-sm text-gray-500 mt-3 max-h-16 overflow-hidden" x-text="product.display_description || product.description"></p>
                            </div>

                            <div class="mt-4 flex items-center justify-between">
                                <div class="inline-flex items-center gap-2">
                                    <span class="text-xs bg-gray-100 text-gray-700 px-2 py-1 rounded-full" x-text="product.tag || ''" x-show="product.tag"></span>
                                    <span class="text-xs bg-gray-100 text-gray-700 px-2 py-1 rounded-full" x-text="product.category || ''"></span>
                                </div>
                                <div>
                                    <span class="inline-flex items-center gap-1 text-purple-600 font-medium">
                                        <svg class="w-5 h-5" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                            <path d="M6 10l2 2 6-6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                        <span class="hidden sm:inline">Select</span>
                                    </span>
                                </div>
                            </div>
                        </button>
                        <div class="absolute top-3 left-3">
                            <span class="inline-flex items-center text-xs px-2 py-1 rounded bg-white/70 text-gray-700">Tap to select</span>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <aside class="bg-white border border-gray-100 rounded-3xl shadow-sm p-6 sticky top-6">
            <p class="text-xs uppercase tracking-wide text-gray-500">Wallet Checkout</p>
            <h3 class="text-2xl font-semibold text-gray-900 mt-2">Quick Buy order</h3>
            <p class="text-sm text-gray-500 mt-2">Wallet-only purchases debit the base price plus 2% platform fee.</p>

            <div class="mt-6 bg-gray-50 rounded-2xl p-4 text-sm text-gray-700">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs text-gray-500">Selected product</p>
                        <p class="font-medium text-gray-900" x-text="selectedProduct()?.name || 'None'">None</p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-gray-500">Base</p>
                        <p class="font-semibold text-purple-600" x-text="formatPrice(selectedBasePrice())">GHS 0.00</p>
                    </div>
                </div>

                <div class="mt-3 flex items-center justify-between">
                    <div class="text-sm text-gray-500">Platform fee (2%)</div>
                    <div class="font-medium" x-text="formatPrice((selectedBasePrice() * 0.02).toFixed(2))">GHS 0.00</div>
                </div>

                <div class="mt-3 border-t pt-3 flex items-center justify-between">
                    <div class="text-sm font-medium">Total wallet charge</div>
                    <div class="text-lg font-bold text-gray-900" x-text="formatPrice(selectedTotalCharge())">GHS 0.00</div>
                </div>

                <p class="mt-2 text-xs text-gray-500">Wallet orders do not generate earnings for the buyer.</p>
            </div>

            <form method="POST" action="{{ route('vendor.quick-buy.store') }}" class="mt-6 space-y-4" x-on:submit="submitting = true">
                @csrf
                <input type="hidden" name="product_id" :value="productId">
                <input type="hidden" name="payment_method" x-model="paymentMethod">

                <div>
                    <label class="text-sm font-medium text-gray-700">Recipient phone</label>
                    <input type="tel" name="recipient_phone_number" x-model="recipient" required placeholder="e.g. 0244 123 456" class="mt-2 w-full rounded-2xl border-gray-200 focus:ring-2 focus:ring-purple-500 px-3 py-2" />
                </div>

                <div x-cloak x-show="paymentMethod !== 'wallet'">
                    <label class="text-sm font-medium text-gray-700">Payer MoMo number</label>
                    <input type="tel" name="mobile_money_number" x-model="momo" placeholder="Same or alternate MoMo" class="mt-2 w-full rounded-2xl border-gray-200 focus:ring-2 focus:ring-purple-500 px-3 py-2" />
                </div>

                <div class="flex gap-2 items-center">
                    <label class="text-sm font-medium text-gray-700 mr-2">Payment</label>
                    <div class="flex gap-2">
                        <button type="button" @click.prevent="paymentMethod='wallet'" :class="paymentMethod==='wallet' ? 'bg-purple-600 text-white' : 'bg-white text-gray-700 border'" class="px-3 py-2 rounded-2xl border">Wallet</button>                    </div>
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

                <button type="submit" class="w-full rounded-2xl bg-purple-600 text-white font-semibold py-3 hover:bg-purple-700 disabled:opacity-60 disabled:cursor-not-allowed flex items-center justify-center gap-3" :disabled="!canPlaceOrder() || submitting">
                    <svg x-show="submitting" class="w-5 h-5 animate-spin text-white" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity="0.2" stroke-width="4"/><path d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="4" stroke-linecap="round"/></svg>
                    <span x-text="submitting ? 'Processing…' : (productId ? (paymentMethod === 'wallet' ? 'Place wallet order' : 'Proceed to payment') : 'Select a product')">Select a product</span>
                </button>
            </form>

            
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
        // current payment method for the quick-buy form. Default to wallet.
        paymentMethod: 'wallet',
        recipient: '',
        momo: '',
        // UI state
        submitting: false,

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
    }));
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
