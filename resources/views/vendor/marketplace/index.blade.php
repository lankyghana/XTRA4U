@extends('layouts.vendor')

@section('title', 'Product Marketplace - XTRA4U')
@section('description', 'Browse and resell products from your affiliate parent')

@section('content')
<x-vendor-layout :vendor="$vendor" title="Product Marketplace" subtitle="Browse and resell products" active="marketplace">
    <div class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-purple-50 py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Header -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-8">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">
                            Product Marketplace
                        </h1>
                        <p class="text-gray-500 mt-1">
                            @if(isset($affiliateParent))
                                Browse products from <span class="font-medium text-purple-600">{{ $affiliateParent->name }}</span>
                            @else
                                Connect to an affiliate parent to browse products
                            @endif
                        </p>
                    </div>
                    <a href="{{ route('vendor.reseller.index') }}" 
                       class="inline-flex items-center gap-3 px-6 py-3 bg-gradient-to-r from-purple-600 to-purple-700 text-white text-base font-semibold rounded-xl hover:from-purple-700 hover:to-purple-800 transition-all shadow-lg shadow-purple-200 hover:shadow-xl hover:shadow-purple-300">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                        </svg>
                        My Reseller Products
                    </a>
                </div>
            </div>

            @if(session('success'))
                <div class="bg-green-50 border border-green-200 text-green-700 px-5 py-4 rounded-xl mb-6 flex items-center gap-3">
                    <svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                    </svg>
                    {{ session('success') }}
                </div>
            @endif

            @if(session('error'))
                <div class="bg-red-50 border border-red-200 text-red-700 px-5 py-4 rounded-xl mb-6 flex items-center gap-3">
                    <svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                    </svg>
                    {{ session('error') }}
                </div>
            @endif

            @if(!isset($affiliateParent))
                <!-- No Affiliate Parent State -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-12 text-center">
                    <div class="w-20 h-20 bg-amber-100 rounded-full flex items-center justify-center mx-auto mb-6">
                        <svg class="w-10 h-10 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path>
                        </svg>
                    </div>
                    <h3 class="text-xl font-semibold text-gray-900 mb-2">No Affiliate Connection</h3>
                    <p class="text-gray-500 max-w-md mx-auto mb-6">
                        You need to connect to an affiliate parent vendor to access the marketplace and resell their products.
                    </p>
                    <a href="{{ route('vendor.affiliates.index') }}" 
                       class="inline-flex items-center gap-2 px-6 py-3 bg-purple-600 text-white font-medium rounded-xl hover:bg-purple-700 transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                        </svg>
                        Join Affiliate Network
                    </a>
                </div>
            @elseif($products->isEmpty())
                <!-- No Products State -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-12 text-center">
                    <div class="w-20 h-20 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-6">
                        <svg class="w-10 h-10 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                        </svg>
                    </div>
                    <h3 class="text-xl font-semibold text-gray-900 mb-2">No Products Available</h3>
                    <p class="text-gray-500 max-w-md mx-auto">
                        <span class="font-medium">{{ $affiliateParent->name }}</span> hasn't added any resellable products yet. Check back later!
                    </p>
                </div>
            @else
                <!-- Products Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    @foreach($products as $product)
                        @php
                            // Parse the JSON description
                            $meta = [];
                            if ($product->description) {
                                $decoded = json_decode($product->description, true);
                                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                    $meta = $decoded;
                                }
                            }
                            
                            $network = $meta['network'] ?? 'Unknown';
                            $category = $meta['category'] ?? 'data';
                            $size = $meta['size'] ?? null;
                            $validity = $meta['validity'] ?? null;
                            $tag = $meta['tag'] ?? null;
                            $notes = $meta['notes'] ?? null;
                            
                            // Get network logo
                            $networkLower = strtolower($network);
                            $networkService = $networkServices->get($networkLower) ?? null;
                            $logoUrl = $networkService ? $networkService->image_url : null;
                            
                            $alreadyReselling = $product->resellerProducts->isNotEmpty();
                            $basePrice = $product->min_base_price ?? $product->price;
                        @endphp

                        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden hover:shadow-md hover:border-purple-200 transition-all duration-200 group">
                            <!-- Product Header with Network Logo -->
                            <div class="p-5 border-b border-gray-100">
                                <div class="flex items-start gap-4">
                                    <!-- Network Logo -->
                                    <div class="flex-shrink-0">
                                        @if($logoUrl)
                                            <img src="{{ $logoUrl }}" alt="{{ $network }}" class="w-14 h-14 rounded-xl object-cover shadow-sm">
                                        @else
                                            <div class="w-14 h-14 rounded-xl bg-gradient-to-br from-purple-500 to-blue-500 flex items-center justify-center shadow-sm">
                                                <span class="text-white font-bold text-lg">{{ strtoupper(substr($network, 0, 2)) }}</span>
                                            </div>
                                        @endif
                                    </div>

                                    <!-- Network & Category Info -->
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 mb-1">
                                            <span class="text-lg font-bold text-gray-900">{{ $network }}</span>
                                            @if($tag)
                                                <span class="px-2 py-0.5 text-xs font-medium bg-green-100 text-green-700 rounded-full">{{ $tag }}</span>
                                            @endif
                                        </div>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-700 capitalize">
                                            {{ $category }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Product Details -->
                            <div class="p-5">
                                <h3 class="text-base font-semibold text-gray-900 mb-3">
                                    {{ $product->name }}
                                </h3>

                                <!-- Product Specs -->
                                <div class="space-y-2 mb-4">
                                    @if($size)
                                        <div class="flex items-center gap-2 text-sm">
                                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"></path>
                                            </svg>
                                            <span class="text-gray-600">Size: <span class="font-medium text-gray-900">{{ $size }}</span></span>
                                        </div>
                                    @endif
                                    @if($validity)
                                        <div class="flex items-center gap-2 text-sm">
                                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                            <span class="text-gray-600">Validity: <span class="font-medium text-gray-900">{{ $validity }}</span></span>
                                        </div>
                                    @endif
                                    @if($notes)
                                        <div class="flex items-start gap-2 text-sm">
                                            <svg class="w-4 h-4 text-gray-400 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                            <span class="text-gray-500">{{ $notes }}</span>
                                        </div>
                                    @endif
                                </div>

                                <!-- Price -->
                                <div class="flex items-center justify-between py-3 px-4 bg-gray-50 rounded-xl mb-4">
                                    <span class="text-sm text-gray-500">Base Price</span>
                                    <span class="text-xl font-bold text-purple-600">₵{{ number_format($basePrice, 2) }}</span>
                                </div>

                                <!-- Action Button -->
                                @if($alreadyReselling)
                                    <div class="flex items-center justify-center gap-2 px-4 py-3 bg-green-50 text-green-700 rounded-xl border border-green-200">
                                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                        </svg>
                                        <span class="text-sm font-medium">Already in Your Catalog</span>
                                    </div>
                                @else
                                    <button 
                                        onclick="openAddModal({{ $product->id }}, '{{ addslashes($product->name) }}', {{ $basePrice }}, '{{ addslashes($network) }}', '{{ $logoUrl ?? '' }}')"
                                        class="w-full flex items-center justify-center gap-2 px-4 py-3 bg-purple-600 text-white font-medium rounded-xl hover:bg-purple-700 transition-colors group-hover:shadow-md">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                        </svg>
                                        Add to My Catalog
                                    </button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                <!-- Pagination -->
                <div class="mt-8">
                    {{ $products->links() }}
                </div>
            @endif
        </div>
    </div>

    <!-- Add Reseller Product Modal -->
    <div id="addModal" class="hidden fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full overflow-hidden">
            <!-- Modal Header -->
            <div class="px-6 py-5 border-b border-gray-100 bg-gradient-to-r from-purple-50 to-blue-50">
                <h3 class="text-xl font-bold text-gray-900">Add to Your Catalog</h3>
                <p class="text-sm text-gray-500 mt-1">Set your markup price to start reselling</p>
            </div>
            
            <form method="POST" action="{{ route('vendor.marketplace.add') }}">
                @csrf
                <input type="hidden" name="product_id" id="modal_product_id">
                
                <div class="p-6">
                    <!-- Product Info -->
                    <div class="flex items-center gap-4 p-4 bg-gray-50 rounded-xl mb-6">
                        <div id="modal_network_logo" class="flex-shrink-0">
                            <!-- Will be populated by JS -->
                        </div>
                        <div class="min-w-0 flex-1">
                            <p id="modal_product_name" class="font-semibold text-gray-900 truncate"></p>
                            <p id="modal_network_name" class="text-sm text-gray-500"></p>
                        </div>
                    </div>

                    <!-- Base Price Display -->
                    <div class="flex items-center justify-between p-4 bg-purple-50 rounded-xl mb-6">
                        <span class="text-sm text-purple-700">Base Price (Owner receives)</span>
                        <span id="modal_base_price" class="text-lg font-bold text-purple-700"></span>
                    </div>

                    <!-- Markup Input -->
                    <div class="mb-6">
                        <label for="markup_price" class="block text-sm font-medium text-gray-700 mb-2">
                            Your Markup (Your Profit)
                        </label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-500 font-medium">₵</span>
                            <input 
                                type="number" 
                                name="markup_price" 
                                id="markup_price"
                                step="0.01"
                                min="0"
                                required
                                placeholder="0.00"
                                oninput="calculateSellingPrice()"
                                class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-lg">
                        </div>
                    </div>

                    <!-- Price Breakdown -->
                    <div class="p-4 bg-gray-50 rounded-xl space-y-3">
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-gray-600">Customer Pays</span>
                            <span id="selling_price" class="text-xl font-bold text-gray-900">₵0.00</span>
                        </div>
                        <div class="border-t border-gray-200 pt-3 space-y-2">
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500">Owner Earning (after 2% fee)</span>
                                <span id="owner_earning" class="text-gray-700">₵0.00</span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500">Your Earning (after 2% fee)</span>
                                <span id="reseller_earning" class="font-medium text-green-600">₵0.00</span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500">Platform Fees</span>
                                <span id="platform_fee" class="text-gray-500">₵0.00</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal Footer -->
                <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex gap-3">
                    <button 
                        type="button"
                        onclick="closeAddModal()"
                        class="flex-1 px-5 py-3 border border-gray-300 text-gray-700 font-medium rounded-xl hover:bg-gray-100 transition-colors">
                        Cancel
                    </button>
                    <button 
                        type="submit"
                        class="flex-1 px-5 py-3 bg-purple-600 text-white font-medium rounded-xl hover:bg-purple-700 transition-colors">
                        Add Product
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let currentBasePrice = 0;

        function openAddModal(productId, productName, basePrice, networkName, logoUrl) {
            currentBasePrice = parseFloat(basePrice);
            document.getElementById('modal_product_id').value = productId;
            document.getElementById('modal_product_name').textContent = productName;
            document.getElementById('modal_network_name').textContent = networkName;
            document.getElementById('modal_base_price').textContent = '₵' + currentBasePrice.toFixed(2);
            
            // Set network logo using safe DOM manipulation (XSS prevention)
            const logoContainer = document.getElementById('modal_network_logo');
            logoContainer.innerHTML = ''; // Clear existing content
            
            if (logoUrl) {
                const img = document.createElement('img');
                img.src = logoUrl;
                img.alt = networkName;
                img.className = 'w-12 h-12 rounded-xl object-cover';
                logoContainer.appendChild(img);
            } else {
                const initials = networkName.substring(0, 2).toUpperCase();
                const div = document.createElement('div');
                div.className = 'w-12 h-12 rounded-xl bg-gradient-to-br from-purple-500 to-blue-500 flex items-center justify-center';
                const span = document.createElement('span');
                span.className = 'text-white font-bold';
                span.textContent = initials;
                div.appendChild(span);
                logoContainer.appendChild(div);
            }
            
            document.getElementById('markup_price').value = '';
            document.getElementById('addModal').classList.remove('hidden');
            calculateSellingPrice();
        }

        function closeAddModal() {
            document.getElementById('addModal').classList.add('hidden');
        }

        function calculateSellingPrice() {
            const markup = parseFloat(document.getElementById('markup_price').value) || 0;
            const sellingPrice = currentBasePrice + markup;
            
            // Calculate platform fees (2% from each party)
            const ownerFee = currentBasePrice * 0.02;
            const resellerFee = markup * 0.02;
            const totalPlatformFee = ownerFee + resellerFee;
            
            const ownerEarning = currentBasePrice - ownerFee;
            const resellerEarning = markup - resellerFee;
            
            document.getElementById('selling_price').textContent = '₵' + sellingPrice.toFixed(2);
            document.getElementById('owner_earning').textContent = '₵' + ownerEarning.toFixed(2);
            document.getElementById('reseller_earning').textContent = '₵' + resellerEarning.toFixed(2);
            document.getElementById('platform_fee').textContent = '₵' + totalPlatformFee.toFixed(2);
        }

        // Close modal on outside click
        document.getElementById('addModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeAddModal();
            }
        });

        // Close modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAddModal();
            }
        });
    </script>
</x-vendor-layout>
@endsection
