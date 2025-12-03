@extends('layouts.app')
@php
    use Illuminate\Support\Str;
@endphp

@section('content')
@php($metadata = $metadata ?? [])

<div class="min-h-screen flex items-center justify-center bg-gray-100 py-10 px-4">
    <div class="w-full max-w-2xl bg-white rounded-2xl shadow-xl p-8 space-y-6">
        <div class="text-center space-y-1">
            <p class="text-sm font-semibold text-gray-500 uppercase tracking-wide">Catalog Builder</p>
            <h2 class="text-3xl font-bold">Edit Product</h2>
            <p class="text-gray-500">Update bundle metadata to keep the storefront accurate.</p>
        </div>
        @if ($errors->any())
            <div class="mb-4 text-red-600">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        <form method="POST" action="{{ route('product.update', $product->id) }}" class="space-y-6">
            @csrf
            @method('PUT')
            <div>
                <label for="name" class="block text-sm font-semibold text-gray-700">Product Name</label>
                <input type="text" name="name" id="name" value="{{ old('name', $product->name) }}" class="mt-1 block w-full border-gray-200 rounded-lg shadow-sm focus:border-purple-400 focus:ring-purple-400" required>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="network" class="block text-sm font-semibold text-gray-700">Network / Service</label>
                    <select name="network" id="network" class="mt-1 block w-full border-gray-200 rounded-lg shadow-sm focus:border-purple-400 focus:ring-purple-400">
                        <option value="">Choose network / service</option>
                        @foreach($networkOptions as $network)
                            <option value="{{ $network->name }}" {{ old('network', $metadata['network'] ?? '') === $network->name ? 'selected' : '' }}>
                                {{ $network->name }} ({{ Str::title(str_replace(['-', '_'], ' ', $network->category)) }})
                            </option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-400 mt-1">If you previously selected a network that is now inactive, it remains visible until you pick a new active option.</p>
                </div>
                <div>
                    <label for="category" class="block text-sm font-semibold text-gray-700">Category</label>
                    <select name="category" id="category" class="mt-1 block w-full border-gray-200 rounded-lg shadow-sm focus:border-purple-400 focus:ring-purple-400">
                                            @php($selectedCategory = old('category', $metadata['category'] ?? 'data'))
                                                <option value="data" {{ $selectedCategory === 'data' ? 'selected' : '' }}>Data Bundles</option>
                                                <option value="ecg" {{ $selectedCategory === 'ecg' ? 'selected' : '' }}>ECG</option>
                                                <option value="shop" {{ $selectedCategory === 'shop' ? 'selected' : '' }}>Shop Online</option>
                                                <option value="results" {{ $selectedCategory === 'results' ? 'selected' : '' }}>Results Checkers</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="size" class="block text-sm font-semibold text-gray-700">Package Size</label>
                    <input type="text" name="size" id="size" value="{{ old('size', $metadata['size'] ?? '') }}" class="mt-1 block w-full border-gray-200 rounded-lg shadow-sm focus:border-purple-400 focus:ring-purple-400">
                </div>
                <div>
                    <label for="validity" class="block text-sm font-semibold text-gray-700">Validity</label>
                    <input type="text" name="validity" id="validity" value="{{ old('validity', $metadata['validity'] ?? '') }}" class="mt-1 block w-full border-gray-200 rounded-lg shadow-sm focus:border-purple-400 focus:ring-purple-400">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="tag" class="block text-sm font-semibold text-gray-700">Promo Tag</label>
                    <input type="text" name="tag" id="tag" value="{{ old('tag', $metadata['tag'] ?? '') }}" class="mt-1 block w-full border-gray-200 rounded-lg shadow-sm focus:border-purple-400 focus:ring-purple-400">
                </div>
                <div>
                    <label for="price" class="block text-sm font-semibold text-gray-700">Price (GHS)</label>
                    <input type="number" name="price" id="price" value="{{ old('price', $product->price) }}" class="mt-1 block w-full border-gray-200 rounded-lg shadow-sm focus:border-purple-400 focus:ring-purple-400" min="0" step="0.01" required>
                </div>
            </div>

            <div>
                <label for="description" class="block text-sm font-semibold text-gray-700">Internal Description</label>
                <textarea name="description" id="description" rows="3" class="mt-1 block w-full border-gray-200 rounded-lg shadow-sm focus:border-purple-400 focus:ring-purple-400">{{ old('description', $metadata['description'] ?? $product->description) }}</textarea>
            </div>

            <div>
                <label for="notes" class="block text-sm font-semibold text-gray-700">Customer-facing Notes</label>
                <textarea name="notes" id="notes" rows="3" class="mt-1 block w-full border-gray-200 rounded-lg shadow-sm focus:border-purple-400 focus:ring-purple-400">{{ old('notes', $metadata['notes'] ?? '') }}</textarea>
            </div>

            <div class="flex items-center gap-3">
                <input type="checkbox" name="is_active" id="is_active" value="1" {{ old('is_active', $product->is_active) ? 'checked' : '' }} class="h-4 w-4 text-purple-600 border-gray-300 rounded">
                <label for="is_active" class="text-sm font-semibold text-gray-700">Active</label>
            </div>

            <button type="submit" class="w-full bg-[#7C3AED] text-white py-3 px-4 rounded-xl font-semibold shadow-md hover:bg-purple-700 transition">Update Product</button>
        </form>
    </div>
</div>
@endsection
