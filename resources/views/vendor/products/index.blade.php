@extends('layouts.vendor')

@section('title', 'Manage Products - XTRA4U')

@section('content')
@php use Illuminate\Support\Str; @endphp
<x-vendor-layout :vendor="$vendor" title="Products" subtitle="Manage the services you offer on XTRA4U" active="products">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Products</h1>
            <p class="text-sm text-gray-500">Keep your catalog up to date for storefront shoppers.</p>
        </div>
        <x-button href="{{ route('vendor.products.create') }}" variant="primary">
            Add New Product
        </x-button>
    </div>

    @if (session('success'))
        <div class="mb-4 bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg">
            {{ session('success') }}
        </div>
    @endif

    <x-card>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Price</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse ($products as $product)
                        @php
                            $meta = null;
                            if ($product->description) {
                                $decoded = json_decode($product->description, true);
                                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                    $meta = $decoded;
                                }
                            }
                            $summary = $meta
                                ? ($meta['notes'] ?? $meta['description'] ?? '—')
                                : Str::limit((string) $product->description, 60);
                        @endphp
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900">{{ $product->name }}</div>
                                <div class="text-sm text-gray-500">{{ $summary }}</div>
                                <div class="flex flex-wrap gap-2 mt-2">
                                    @if (! empty($meta['network']))
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-purple-50 text-purple-700 border border-purple-100">{{ $meta['network'] }}</span>
                                    @endif
                                    @if (! empty($meta['size']))
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-gray-100 text-gray-700">{{ $meta['size'] }}</span>
                                    @endif
                                    @if (! empty($meta['validity']))
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-green-50 text-green-700">{{ $meta['validity'] }}</span>
                                    @endif
                                    @if (! empty($meta['tag']))
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-amber-50 text-amber-700">{{ $meta['tag'] }}</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">GHS {{ number_format($product->price, 2) }}</td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                @if ($product->is_active)
                                    <x-badge variant="completed" size="sm">Active</x-badge>
                                @else
                                    <x-badge variant="pending" size="sm">Inactive</x-badge>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <x-button href="{{ route('product.edit', $product->id) }}" variant="outline" size="sm">
                                    Edit
                                </x-button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-4 text-center text-sm text-gray-500">
                                You have not added any products yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="px-6 py-4 border-t border-gray-100">
            {{ $products->links() }}
        </div>
    </x-card>
</x-vendor-layout>
@endsection
