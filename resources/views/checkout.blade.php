@extends('layouts.app')

@section('title', 'Marketplace - XTRA4U')
@section('description', 'Browse all available services from all vendors')

@section('content')
<div
    <div class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-purple-50 py-6 lg:py-10" 
    x-data="{
        networkGradients: @json(collect(config('momo.product_networks', []))->mapWithKeys(fn ($n, $k) => [$k => ($n['gradient'] ?? null)])->filter()->all()),
        allProducts: [],
        selectedProduct: null,
        searchQuery: '',
        selectedNetwork: '',
        sortBy: 'random',
        recipientPhone: '',
        momoNumber: '',
        isSubmitting: false,
        currentVendor: @json($currentVendor ?? null),
        paymentMethod: 'gateway',
        
        init() {
            this.allProducts = JSON.parse(document.getElementById('products-data').textContent);
            if (this.currentVendor) {
                // default to wallet if vendor has sufficient balance for first product
                this.paymentMethod = 'wallet';
            }
        },

        selectedBasePrice() {
            return this.selectedProduct ? parseFloat(this.selectedProduct.price || 0) : 0;
        },

        vendorBalance() {
            return this.currentVendor ? parseFloat(this.currentVendor.wallet_balance || 0) : 0;
        },

        get filteredProducts() {
            let filtered = [...this.allProducts];

            if (this.searchQuery) {
                const query = this.searchQuery.toLowerCase();
                filtered = filtered.filter(p => 
                    (p.name && p.name.toLowerCase().includes(query)) ||
                    (p.vendor_name && p.vendor_name.toLowerCase().includes(query)) ||
                    (p.description && p.description.toLowerCase().includes(query)) ||
                    (p.network && p.network.toLowerCase().includes(query)) ||
                    (p.size && p.size.toLowerCase().includes(query))
                );
            }

            if (this.selectedNetwork) {
                filtered = filtered.filter(p => p.network === this.selectedNetwork);
            }

            if (this.sortBy === 'price_low') {
                filtered.sort((a, b) => parseFloat(a.price) - parseFloat(b.price));
            } else if (this.sortBy === 'price_high') {
                filtered.sort((a, b) => parseFloat(b.price) - parseFloat(a.price));
            } else if (this.sortBy === 'name') {
                filtered.sort((a, b) => (a.name || '').localeCompare(b.name || ''));
            } else if (this.sortBy === 'vendor') {
                filtered.sort((a, b) => (a.vendor_name || '').localeCompare(b.vendor_name || ''));
            }

            return filtered;
        },

        isSelected(product) {
            if (!this.selectedProduct) return false;
            return this.selectedProduct.id === product.id && 
                   this.selectedProduct.is_reseller_product === product.is_reseller_product;
        },

        selectProduct(product) {
            if (this.isSelected(product)) {
                this.selectedProduct = null;
            } else {
                this.selectedProduct = product;
            }
        },

        clearFilters() {
            this.searchQuery = '';
            this.selectedNetwork = '';
            this.sortBy = 'random';
        },

        shuffleProducts() {
            for (let i = this.allProducts.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [this.allProducts[i], this.allProducts[j]] = [this.allProducts[j], this.allProducts[i]];
            }
            this.sortBy = 'random';
        },

        formatCurrency(amount) {
            if (!amount) return 'GHS 0.00';
            return 'GHS ' + parseFloat(amount).toFixed(2);
        },

        async submitPayment(event) {
            if (!this.selectedProduct || this.isSubmitting) return;

            if (!this.recipientPhone || !this.momoNumber) {
                alert('Please enter both recipient phone number and MoMo number');
                return;
            }

            this.isSubmitting = true;

            try {
                const form = event.target;
                const formData = new FormData(form);

                const response = await fetch(form.action, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                    }
                });

                const data = await response.json();

                if (data.authorization_url) {
                    window.location.href = data.authorization_url;
                } else if (data.success) {
                    window.location.href = data.redirect || '/checkout/success';
                } else {
                    alert(data.message || 'Payment failed. Please try again.');
                    this.isSubmitting = false;
                }
            } catch (error) {
                console.error('Payment error:', error);
                alert('An error occurred. Please try again.');
                this.isSubmitting = false;
            }
        }
    }"
>
    <!-- Hidden JSON data -->
    <script type="application/json" id="products-data">@json($products)</script>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-gradient-to-br from-purple-600 to-indigo-600 rounded-2xl shadow-lg shadow-purple-200 mb-4">
                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path>
                </svg>
            </div>
            <h1 class="text-3xl font-bold text-gray-900">Marketplace</h1>
            <p class="mt-2 text-gray-500">Browse services from all vendors • Compare prices • Find the best deals</p>
        </div>

        <!-- Search and Filters -->
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 mb-6">
            <div class="flex flex-col lg:flex-row gap-4">
                <!-- Search -->
                <div class="flex-1">
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </div>
                        <input 
                            type="text"
                            x-model="searchQuery"
                            placeholder="Search services, vendors, data bundles..."
                            class="w-full pl-12 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-gray-900 placeholder-gray-400 focus:bg-white focus:border-purple-500 focus:ring-2 focus:ring-purple-200 transition-all"
                        >
                    </div>
                </div>
                
                <!-- Network Filter -->
                <div class="w-full lg:w-48">
                    <select
                        x-model="selectedNetwork"
                        class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-gray-900 focus:bg-white focus:border-purple-500 focus:ring-2 focus:ring-purple-200 transition-all"
                    >
                        <option value="">All Networks</option>
                        @foreach (config('momo.product_networks', []) as $networkValue => $network)
                            <option value="{{ $networkValue }}">{{ $network['label'] ?? $networkValue }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Sort -->
                <div class="w-full lg:w-48">
                    <select
                        x-model="sortBy"
                        class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-gray-900 focus:bg-white focus:border-purple-500 focus:ring-2 focus:ring-purple-200 transition-all"
                    >
                        <option value="random">Random Order</option>
                        <option value="price_low">Price: Low to High</option>
                        <option value="price_high">Price: High to Low</option>
                        <option value="name">Name: A-Z</option>
                        <option value="vendor">Vendor: A-Z</option>
                    </select>
                </div>
            </div>

            <!-- Active Filters & Results Count -->
            <div class="flex flex-wrap items-center justify-between mt-4 pt-4 border-t border-gray-100">
                <div class="flex flex-wrap gap-2">
                    <span class="text-sm text-gray-500">
                        Showing <span class="font-semibold text-purple-600" x-text="filteredProducts.length"></span> services
                    </span>
                    <template x-if="searchQuery || selectedNetwork">
                        <button @click="clearFilters()" class="text-sm text-purple-600 hover:text-purple-800 font-medium">
                            Clear filters
                        </button>
                    </template>
                </div>
                <button 
                    @click="shuffleProducts()"
                    class="text-sm text-gray-500 hover:text-purple-600 flex items-center gap-1 transition-colors"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                    Shuffle
                </button>
            </div>
        </div>

        <!-- Products Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            <template x-for="product in filteredProducts" :key="product.id + '-' + (product.is_reseller_product ? 'r' : 'o')">
                <div 
                    @click="selectProduct(product)"
                    class="bg-white rounded-2xl shadow-sm border-2 overflow-hidden cursor-pointer transition-all duration-200 hover:shadow-lg hover:border-purple-200 group"
                    :class="isSelected(product) ? 'border-purple-500 ring-2 ring-purple-200' : 'border-gray-100'"
                >
                    <!-- Network Banner -->
                    <div class="h-2 bg-gradient-to-r"
                        :class="(product.network && networkGradients[product.network]) ? networkGradients[product.network] : 'from-purple-500 to-indigo-500'">
                    </div>
                    
                    <div class="p-4">
                        <!-- Vendor Badge & Reseller Tag -->
                        <div class="flex items-center justify-between mb-3">
                            <span class="inline-flex items-center gap-1.5 px-2 py-1 bg-gray-100 text-gray-600 rounded-lg text-xs font-medium">
                                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"></path>
                                </svg>
                                <span x-text="product.vendor_name"></span>
                            </span>
                            <template x-if="product.is_reseller_product">
                                <span class="px-2 py-0.5 bg-blue-100 text-blue-700 rounded text-xs font-medium">Reseller</span>
                            </template>
                        </div>

                        <!-- Product Name -->
                        <h3 class="font-bold text-gray-900 text-lg mb-2 line-clamp-1" x-text="product.name"></h3>
                        
                        <!-- Description -->
                        <p class="text-sm text-gray-500 mb-3 line-clamp-2" x-text="product.description || 'Data bundle service'"></p>

                        <!-- Tags -->
                        <div class="flex flex-wrap gap-1.5 mb-4">
                            <template x-if="product.network">
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-purple-100 text-purple-700 rounded text-xs font-medium">
                                    <span x-text="product.network"></span>
                                </span>
                            </template>
                            <template x-if="product.size">
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-blue-100 text-blue-700 rounded text-xs font-medium">
                                    <span x-text="product.size"></span>
                                </span>
                            </template>
                            <template x-if="product.validity">
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-green-100 text-green-700 rounded text-xs font-medium">
                                    <span x-text="product.validity"></span>
                                </span>
                            </template>
                        </div>

                        <!-- Price & Select -->
                        <div class="flex items-center justify-between pt-3 border-t border-gray-100">
                            <div>
                                <p class="text-2xl font-bold text-purple-600" x-text="formatCurrency(product.price)"></p>
                            </div>
                            <div class="flex items-center gap-2">
                                <template x-if="isSelected(product)">
                                    <span class="flex items-center gap-1 text-green-600 text-sm font-medium">
                                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                        </svg>
                                        Selected
                                    </span>
                                </template>
                                <template x-if="!isSelected(product)">
                                    <span class="text-sm text-gray-400 group-hover:text-purple-600 transition-colors">
                                        Tap to select
                                    </span>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <!-- Empty State -->
        <div x-show="filteredProducts.length === 0" class="text-center py-16">
            <div class="w-20 h-20 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg class="w-10 h-10 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
            <h3 class="text-lg font-semibold text-gray-900 mb-2">No services found</h3>
            <p class="text-gray-500 mb-4">Try adjusting your filters or search terms</p>
            <button @click="clearFilters()" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors">
                Clear all filters
            </button>
        </div>
    </div>

    <!-- Sticky Purchase Panel -->
    <div 
        x-show="selectedProduct"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="translate-y-full opacity-0"
        x-transition:enter-end="translate-y-0 opacity-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="translate-y-0 opacity-100"
        x-transition:leave-end="translate-y-full opacity-0"
        class="fixed bottom-0 inset-x-0 bg-white border-t border-gray-200 shadow-2xl z-50"
        x-cloak
    >
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
            <div class="flex flex-col lg:flex-row lg:items-center gap-4">
                <!-- Selected Product Info -->
                <div class="flex-1 flex items-center gap-4">
                    <div class="w-12 h-12 bg-gradient-to-br from-purple-500 to-indigo-500 rounded-xl flex items-center justify-center flex-shrink-0">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="font-bold text-gray-900 truncate" x-text="selectedProduct ? selectedProduct.name : ''"></p>
                        <p class="text-sm text-gray-500">
                            <span x-text="selectedProduct ? selectedProduct.vendor_name : ''"></span> •
                            <span class="text-purple-600 font-semibold" x-text="selectedProduct ? formatCurrency(selectedProduct.price) : ''"></span>
                        </p>
                    </div>
                    <button @click="selectedProduct = null" class="p-2 text-gray-400 hover:text-gray-600 lg:hidden">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>

                <!-- Purchase Form -->
                <form method="POST" action="{{ route('purchase') }}" @submit.prevent="submitPayment($event)" class="flex flex-col sm:flex-row gap-3 lg:gap-4">
                    @csrf
                    <input type="hidden" name="service_purchased" :value="selectedProduct ? selectedProduct.name : ''">
                    <input type="hidden" name="amount_paid" :value="selectedProduct ? selectedProduct.price : ''">
                    <input type="hidden" name="vendor_service_id" :value="selectedProduct ? (selectedProduct.original_product_id || selectedProduct.product_id || selectedProduct.id) : ''">
                    <input type="hidden" name="vendor_id" :value="selectedProduct ? selectedProduct.vendor_id : ''">
                    <input type="hidden" name="is_reseller_product" :value="selectedProduct && selectedProduct.is_reseller_product ? '1' : '0'">
                    <input type="hidden" name="reseller_product_id" :value="selectedProduct ? (selectedProduct.reseller_product_id || '') : ''">
                    
                    <div class="flex-1 sm:w-40">
                        <input 
                            type="tel"
                            name="recipient_phone_number"
                            x-model="recipientPhone"
                            placeholder="Recipient Phone"
                            inputmode="tel"
                            autocomplete="tel"
                            required
                            class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-gray-900 placeholder-gray-400 focus:bg-white focus:border-purple-500 focus:ring-2 focus:ring-purple-200 transition-all text-sm"
                        >
                    </div>
                    <div class="flex-1 sm:w-40">
                        <input 
                            type="tel"
                            name="mobile_money_number"
                            x-model="momoNumber"
                            placeholder="MoMo Number"
                            inputmode="tel"
                            autocomplete="tel"
                            required
                            class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-gray-900 placeholder-gray-400 focus:bg-white focus:border-purple-500 focus:ring-2 focus:ring-purple-200 transition-all text-sm"
                        >
                    </div>
                    <template x-if="currentVendor">
                        <div class="w-full sm:w-auto flex items-center gap-4">
                            <label class="inline-flex items-center text-sm">
                                <input type="radio" name="payment_method" value="wallet" x-model="paymentMethod" class="form-radio" />
                                <span class="ml-2">Wallet (Balance: GHS <span x-text="vendorBalance().toFixed(2)"></span>)</span>
                            </label>
                            <label class="inline-flex items-center text-sm">
                                <input type="radio" name="payment_method" value="gateway" x-model="paymentMethod" class="form-radio" />
                                <span class="ml-2">Payment Gateway</span>
                            </label>
                        </div>
                    </template>

                    <input type="hidden" name="pay_with_wallet" :value="paymentMethod === 'wallet' ? 1 : 0">
                    <button 
                        type="submit"
                        :disabled="isSubmitting || !selectedProduct || (paymentMethod === 'wallet' && selectedBasePrice() > vendorBalance())"
                        class="w-full sm:w-auto px-8 py-3 bg-gradient-to-r from-purple-600 to-indigo-600 text-white font-semibold rounded-xl hover:from-purple-700 hover:to-indigo-700 focus:ring-4 focus:ring-purple-200 disabled:opacity-50 disabled:cursor-not-allowed transition-all flex items-center justify-center gap-2"
                    >
                        <template x-if="!isSubmitting">
                            <span class="flex items-center gap-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                </svg>
                                Pay <span x-text="selectedProduct ? formatCurrency(selectedProduct.price) : ''"></span>
                            </span>
                        </template>
                        <template x-if="isSubmitting">
                            <span class="flex items-center gap-2">
                                <svg class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Processing...
                            </span>
                        </template>
                    </button>
                </form>

                <!-- Close button for desktop -->
                <button @click="selectedProduct = null" class="hidden lg:block p-2 text-gray-400 hover:text-gray-600">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Spacer for sticky panel -->
    <div x-show="selectedProduct" class="h-32 lg:h-24" x-cloak></div>
</div>
@endsection

