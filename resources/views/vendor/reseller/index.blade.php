@extends('layouts.vendor')

@section('title', 'My Reseller Products - XTRA4U')
@section('description', 'Manage your reseller catalog and markups')

@section('content')
<x-vendor-layout :vendor="$vendor" title="My Reseller Products" subtitle="Manage your reseller catalog" active="reseller">
    <div>
        <div class="max-w-7xl mx-auto">
            <!-- Header -->
            <div class="bg-white rounded-xl border border-gray-200 p-6 mb-8">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">My Reseller Products</h1>
                        <p class="text-gray-500 mt-1">Manage your reseller catalog and markups</p>
                    </div>
                    <a href="{{ route('vendor.marketplace.index') }}"
                       class="inline-flex items-center gap-3 px-6 py-3 bg-brand-violet text-white text-base font-semibold rounded-xl hover:bg-brand-violet-deep transition-colors shadow-sm">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path>
                        </svg>
                        Browse Marketplace
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

            @if($resellerProducts->isEmpty())
                <!-- Empty State -->
                <div class="bg-white rounded-xl border border-gray-200 p-12 text-center">
                    <div class="w-20 h-20 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-6">
                        <svg class="w-10 h-10 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                        </svg>
                    </div>
                    <h3 class="text-xl font-semibold text-gray-900 mb-2">No Reseller Products Yet</h3>
                    <p class="text-gray-500 max-w-md mx-auto mb-6">
                        Start adding products from the marketplace to build your reseller catalog
                    </p>
                    <a href="{{ route('vendor.marketplace.index') }}" 
                       class="inline-flex items-center gap-2 px-6 py-3 bg-brand-violet text-white font-medium rounded-xl hover:bg-brand-violet-deep transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path>
                        </svg>
                        Browse Marketplace
                    </a>
                </div>
            @else
                <!-- Products Grid -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    @foreach($resellerProducts as $resellerProduct)
                        @php
                            // Parse the JSON description
                            $meta = [];
                            $productDesc = $resellerProduct->product->description ?? '';
                            if ($productDesc) {
                                $decoded = json_decode($productDesc, true);
                                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                    $meta = $decoded;
                                }
                            }
                            
                            $network = $meta['network'] ?? 'Unknown';
                            $category = $meta['category'] ?? 'data';
                            $size = $meta['size'] ?? null;
                            $validity = $meta['validity'] ?? null;
                            $tag = $meta['tag'] ?? null;
                            
                            // Get network logo
                            $networkLower = strtolower($network);
                            $networkService = $networkServices->get($networkLower) ?? null;
                            $logoUrl = $networkService ? $networkService->image_url : null;
                        @endphp

                        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden hover:shadow-md hover:border-brand-violet transition-all duration-200">
                            <div class="p-5">
                                <div class="flex items-start gap-4">
                                    <!-- Network Logo -->
                                    <div class="flex-shrink-0">
                                        @if($logoUrl)
                                            <img src="{{ $logoUrl }}" alt="{{ $network }}" class="w-16 h-16 rounded-xl object-cover shadow-sm">
                                        @else
                                            <div class="w-16 h-16 rounded-xl bg-gradient-to-br from-brand-violet to-brand-violet-deep flex items-center justify-center shadow-sm">
                                                <span class="text-white font-bold text-lg">{{ strtoupper(substr($network, 0, 2)) }}</span>
                                            </div>
                                        @endif
                                    </div>

                                    <!-- Product Info -->
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-start justify-between gap-2 mb-1">
                                            <div>
                                                <div class="flex items-center gap-2">
                                                    <span class="font-bold text-gray-900">{{ $network }}</span>
                                                    @if($tag)
                                                        <span class="px-2 py-0.5 text-xs font-medium bg-green-100 text-green-700 rounded-full">{{ $tag }}</span>
                                                    @endif
                                                </div>
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-brand-violet-soft text-brand-violet-deep capitalize mt-1">
                                                    {{ $category }}
                                                </span>
                                            </div>
                                            
                                            <!-- Status Badge -->
                                            <span class="px-2.5 py-1 rounded-full text-xs font-medium {{ $resellerProduct->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' }}">
                                                {{ $resellerProduct->is_active ? 'Active' : 'Inactive' }}
                                            </span>
                                        </div>
                                        
                                        <h3 class="text-sm font-semibold text-gray-800 mt-2 line-clamp-1">
                                            {{ $resellerProduct->product->name }}
                                        </h3>
                                        
                                        <!-- Product Specs -->
                                        <div class="flex items-center gap-4 mt-2 text-xs text-gray-500">
                                            @if($size)
                                                <span class="flex items-center gap-1">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"></path>
                                                    </svg>
                                                    {{ $size }}
                                                </span>
                                            @endif
                                            @if($validity)
                                                <span class="flex items-center gap-1">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                    </svg>
                                                    {{ $validity }}
                                                </span>
                                            @endif
                                            <span class="text-gray-400">
                                                From: {{ $resellerProduct->ownerVendor->name }}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Pricing Grid -->
                                <div class="grid grid-cols-4 gap-3 mt-4 p-3 bg-gray-50 rounded-xl">
                                    <div class="text-center">
                                        <p class="text-xs text-gray-500 mb-0.5">Base</p>
                                        <p class="text-sm font-semibold text-gray-800">₵{{ number_format($resellerProduct->base_price, 2) }}</p>
                                    </div>
                                    <div class="text-center">
                                        <p class="text-xs text-gray-500 mb-0.5">Markup</p>
                                        <p class="text-sm font-semibold text-green-600">+₵{{ number_format($resellerProduct->markup_price, 2) }}</p>
                                    </div>
                                    <div class="text-center">
                                        <p class="text-xs text-gray-500 mb-0.5">Selling</p>
                                        <p class="text-sm font-bold text-brand-violet">₵{{ number_format($resellerProduct->selling_price, 2) }}</p>
                                    </div>
                                    <div class="text-center">
                                        <p class="text-xs text-gray-500 mb-0.5">Profit</p>
                                        <p class="text-sm font-bold text-blue-600">₵{{ number_format($resellerProduct->markup_price * 0.98, 2) }}</p>
                                    </div>
                                </div>

                                <!-- Action Buttons -->
                                <div class="flex items-center gap-2 mt-4">
                                    <button 
                                        onclick="openEditModal({{ $resellerProduct->id }}, '{{ addslashes($resellerProduct->product->name) }}', {{ $resellerProduct->base_price }}, {{ $resellerProduct->markup_price }}, {{ $resellerProduct->is_active ? 'true' : 'false' }}, '{{ addslashes($network) }}', '{{ $logoUrl ?? '' }}')"
                                        class="flex-1 flex items-center justify-center gap-2 px-4 py-2 bg-brand-violet-soft text-brand-violet-deep rounded-xl hover:bg-brand-violet-soft transition-colors text-sm font-medium">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                        </svg>
                                        Edit Markup
                                    </button>
                                    
                                    <form method="POST" action="{{ route('vendor.reseller.update', $resellerProduct->id) }}" class="flex-shrink-0">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="markup_price" value="{{ $resellerProduct->markup_price }}">
                                        <input type="hidden" name="is_active" value="{{ $resellerProduct->is_active ? '0' : '1' }}">
                                        <button type="submit" class="px-4 py-2 {{ $resellerProduct->is_active ? 'bg-amber-50 text-amber-700 hover:bg-amber-100' : 'bg-green-50 text-green-700 hover:bg-green-100' }} rounded-xl transition-colors text-sm font-medium">
                                            {{ $resellerProduct->is_active ? 'Pause' : 'Activate' }}
                                        </button>
                                    </form>
                                    
                                    <form method="POST" action="{{ route('vendor.reseller.destroy', $resellerProduct->id) }}" 
                                          onsubmit="return confirm('Remove this product from your catalog?')" class="flex-shrink-0">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="p-2 text-red-600 hover:bg-red-50 rounded-xl transition-colors">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                            </svg>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <!-- Pagination -->
                <div class="mt-8">
                    {{ $resellerProducts->links() }}
                </div>
            @endif
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="hidden fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full overflow-hidden">
            <!-- Modal Header -->
            <div class="px-6 py-5 border-b border-gray-100 bg-gray-50">
                <h3 class="text-xl font-bold text-gray-900">Edit Reseller Product</h3>
                <p class="text-sm text-gray-500 mt-1">Update your markup price</p>
            </div>
            
            <form method="POST" id="editForm">
                @csrf
                @method('PATCH')
                
                <div class="p-6">
                    <!-- Product Info -->
                    <div class="flex items-center gap-4 p-4 bg-gray-50 rounded-xl mb-6">
                        <div id="edit_network_logo" class="flex-shrink-0">
                            <!-- Will be populated by JS -->
                        </div>
                        <div class="min-w-0 flex-1">
                            <p id="edit_product_name" class="font-semibold text-gray-900 truncate"></p>
                            <p id="edit_network_name" class="text-sm text-gray-500"></p>
                        </div>
                    </div>

                    <!-- Base Price Display -->
                    <div class="flex items-center justify-between p-4 bg-brand-violet-soft rounded-xl mb-6">
                        <span class="text-sm text-brand-violet-deep">Base Price</span>
                        <span id="edit_base_price" class="text-lg font-bold text-brand-violet-deep"></span>
                    </div>

                    <!-- Markup Input -->
                    <div class="mb-6">
                        <label for="edit_markup_price" class="block text-sm font-medium text-gray-700 mb-2">
                            Your Markup (Your Profit)
                        </label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-500 font-medium">₵</span>
                            <input 
                                type="number" 
                                name="markup_price" 
                                id="edit_markup_price"
                                step="0.01"
                                min="0"
                                required
                                oninput="calculateEditSellingPrice()"
                                class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-brand-violet focus:border-brand-violet text-lg">
                        </div>
                    </div>

                    <!-- Active Toggle -->
                    <div class="mb-6">
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" id="edit_is_active" value="1" class="w-5 h-5 text-brand-violet rounded border-gray-300 focus:ring-brand-violet">
                            <span class="text-sm font-medium text-gray-700">Product is Active</span>
                        </label>
                    </div>

                    <!-- Price Breakdown -->
                    <div class="p-4 bg-gray-50 rounded-xl space-y-3">
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-gray-600">Customer Pays</span>
                            <span id="edit_selling_price" class="text-xl font-bold text-gray-900">₵0.00</span>
                        </div>
                        <div class="border-t border-gray-200 pt-3">
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500">Your Earning</span>
                                <span id="edit_reseller_earning" class="font-medium text-green-600">₵0.00</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal Footer -->
                <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex gap-3">
                    <button 
                        type="button"
                        onclick="closeEditModal()"
                        class="flex-1 px-5 py-3 border border-gray-300 text-gray-700 font-medium rounded-xl hover:bg-gray-100 transition-colors">
                        Cancel
                    </button>
                    <button 
                        type="submit"
                        class="flex-1 px-5 py-3 bg-brand-violet text-white font-medium rounded-xl hover:bg-brand-violet-deep transition-colors">
                        Update Product
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let editBasePrice = 0;

        function openEditModal(id, name, basePrice, markup, isActive, networkName, logoUrl) {
            editBasePrice = parseFloat(basePrice);
            document.getElementById('editForm').action = `/vendor/reseller-products/${id}`;
            document.getElementById('edit_product_name').textContent = name;
            document.getElementById('edit_network_name').textContent = networkName;
            document.getElementById('edit_base_price').textContent = '₵' + editBasePrice.toFixed(2);
            document.getElementById('edit_markup_price').value = markup;
            document.getElementById('edit_is_active').checked = isActive;
            
            // Set network logo using safe DOM manipulation (XSS prevention)
            const logoContainer = document.getElementById('edit_network_logo');
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
                div.className = 'w-12 h-12 rounded-xl bg-gradient-to-br from-brand-violet to-brand-violet-deep flex items-center justify-center';
                const span = document.createElement('span');
                span.className = 'text-white font-bold';
                span.textContent = initials;
                div.appendChild(span);
                logoContainer.appendChild(div);
            }
            
            document.getElementById('editModal').classList.remove('hidden');
            calculateEditSellingPrice();
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.add('hidden');
        }

        function calculateEditSellingPrice() {
            const markup = parseFloat(document.getElementById('edit_markup_price').value) || 0;
            const sellingPrice = editBasePrice + markup;
            const resellerEarning = markup * 0.98;
            
            document.getElementById('edit_selling_price').textContent = '₵' + sellingPrice.toFixed(2);
            document.getElementById('edit_reseller_earning').textContent = '₵' + resellerEarning.toFixed(2);
        }

        // Close modal on outside click
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeEditModal();
            }
        });

        // Close modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeEditModal();
            }
        });
    </script>
</x-vendor-layout>
@endsection
