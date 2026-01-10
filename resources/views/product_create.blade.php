@extends('layouts.app')
@php
    use Illuminate\Support\Str;
@endphp

@section('content')
<div class="min-h-screen flex items-center justify-center bg-gray-100 py-10 px-4">
    <div class="w-full max-w-2xl bg-white rounded-2xl shadow-xl p-8 space-y-6">
        <div class="text-center space-y-2">
            <p class="text-sm font-semibold text-gray-500 uppercase tracking-wide">Catalog Builder</p>
            <h2 class="text-3xl font-bold">Create Product</h2>
            <p class="text-gray-500">Provide network metadata so your storefront can render rich package cards.</p>
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
        <form method="POST" action="{{ route('product.store') }}" class="space-y-6">
            @csrf
            <div>
                <label for="name" class="block text-sm font-semibold text-gray-700">Product Name</label>
                <input type="text" name="name" id="name" value="{{ old('name') }}" class="mt-1 block w-full border-gray-200 rounded-lg shadow-sm focus:border-brand-bright-blue focus:ring-brand-bright-blue" required>
            </div>

            @php
                $categoryOptions = config('storefront.categories', []);
                $defaultCategory = config('storefront.default_category') ?? (array_key_first($categoryOptions) ?? 'data');
            @endphp
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="network" class="block text-sm font-semibold text-gray-700">Network / Service</label>
                    <select name="network" id="network" class="mt-1 block w-full border-gray-200 rounded-lg shadow-sm focus:border-purple-400 focus:ring-purple-400">
                        <option value="">Choose network / service</option>
                        @foreach ($networkOptions as $network)
                            <option value="{{ $network->name }}" {{ old('network') === $network->name ? 'selected' : '' }}>{{ $network->name }} ({{ Str::title(str_replace(['-', '_'], ' ', $network->category)) }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="category" class="block text-sm font-semibold text-gray-700">Category</label>
                    <select name="category" id="category" class="mt-1 block w-full border-gray-200 rounded-lg shadow-sm focus:border-purple-400 focus:ring-purple-400">
                        @foreach ($categoryOptions as $value => $option)
                            <option value="{{ $value }}" {{ old('category', $defaultCategory) === $value ? 'selected' : '' }}>{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div>
                <label for="datafyhub_network" class="block text-sm font-semibold text-gray-700">External Fulfillment Network (Datafyhub)</label>
                <select name="datafyhub_network" id="datafyhub_network" class="mt-1 block w-full border-gray-200 rounded-lg shadow-sm focus:border-purple-400 focus:ring-purple-400">
                    <option value="" {{ old('datafyhub_network') === '' ? 'selected' : '' }}>Auto (recommended)</option>
                    <option value="mtn" {{ old('datafyhub_network') === 'mtn' ? 'selected' : '' }}>mtn</option>
                    <option value="telecel" {{ old('datafyhub_network') === 'telecel' ? 'selected' : '' }}>telecel</option>
                    <option value="ishare" {{ old('datafyhub_network') === 'ishare' ? 'selected' : '' }}>ishare</option>
                    <option value="bigtime" {{ old('datafyhub_network') === 'bigtime' ? 'selected' : '' }}>bigtime</option>
                    <option value="xpress" {{ old('datafyhub_network') === 'xpress' ? 'selected' : '' }}>xpress</option>
                </select>
                <p class="text-xs text-gray-400 mt-1">Optional. Used only for External Fulfillment; customers won’t see this.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="size" class="block text-sm font-semibold text-gray-700">Package Size</label>
                    <input type="text" name="size" id="size" value="{{ old('size') }}" placeholder="1GB, 30 units" class="mt-1 block w-full border-gray-200 rounded-lg shadow-sm focus:border-purple-400 focus:ring-purple-400">
                </div>
                <div>
                    <label for="validity" class="block text-sm font-semibold text-gray-700">Validity</label>
                    <input type="text" name="validity" id="validity" value="{{ old('validity') }}" placeholder="Non-expiry, 30 days" class="mt-1 block w-full border-gray-200 rounded-lg shadow-sm focus:border-purple-400 focus:ring-purple-400">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="tag" class="block text-sm font-semibold text-gray-700">Promo Tag</label>
                    <input type="text" name="tag" id="tag" value="{{ old('tag') }}" placeholder="Special Rate" class="mt-1 block w-full border-gray-200 rounded-lg shadow-sm focus:border-purple-400 focus:ring-purple-400">
                </div>
                <div>
                    <label for="price" class="block text-sm font-semibold text-gray-700">Price (GHS)</label>
                    <input type="number" name="price" id="price" value="{{ old('price') }}" class="mt-1 block w-full border-gray-200 rounded-lg shadow-sm focus:border-purple-400 focus:ring-purple-400" min="0" step="0.01" required>
                </div>
            </div>

            <div>
                <label for="description" class="block text-sm font-semibold text-gray-700">Internal Description</label>
                <textarea name="description" id="description" rows="3" class="mt-1 block w-full border-gray-200 rounded-lg shadow-sm focus:border-purple-400 focus:ring-purple-400" placeholder="Optional additional info for your records">{{ old('description') }}</textarea>
            </div>

            <div>
                <label for="notes" class="block text-sm font-semibold text-gray-700">Customer-facing Notes</label>
                <textarea name="notes" id="notes" rows="3" class="mt-1 block w-full border-gray-200 rounded-lg shadow-sm focus:border-purple-400 focus:ring-purple-400" placeholder="Shown on storefront cards">{{ old('notes') }}</textarea>
                <p class="mt-1 text-xs text-gray-400">These fields will be serialized into JSON so the storefront can render metadata like network, size, validity, and promotions.</p>
            </div>

            <button type="submit" class="w-full bg-[#7C3AED] text-white py-3 px-4 rounded-xl font-semibold shadow-md hover:bg-purple-700 transition">Create Product</button>
        </form>
    </div>
</div>
@endsection
