@extends('layouts.vendor')

@section('title', 'Manage Products - XTRA4U')

@section('content')
@php use Illuminate\Support\Str; @endphp
<x-vendor-layout :vendor="$vendor" title="Products" subtitle="Manage the services you offer on XTRA4U" active="products">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Products</h1>
            <p class="text-sm text-gray-600">Keep your catalog up to date for storefront shoppers.</p>
        </div>
        <x-button href="{{ route('vendor.products.create') }}" variant="primary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
            </svg>
            Add New Product
        </x-button>
    </div>

    @if (session('success'))
        <div class="mb-6 bg-gradient-to-r from-green-50 to-emerald-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg shadow-md">
            {{ session('success') }}
        </div>
    @endif

    <div class="bg-gradient-to-br from-white to-gray-50 rounded-xl shadow-lg border border-gray-200">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gradient-to-r from-gray-50 to-slate-100">
                    <tr>
                        <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Name</th>
                        <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Price</th>
                        <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-4 text-right text-xs font-bold text-gray-700 uppercase tracking-wider">Actions</th>
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
                        <tr class="hover:bg-gradient-to-r hover:from-blue-50 hover:to-purple-50 transition-all duration-200">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-semibold text-gray-900">{{ $product->name }}</div>
                                <div class="text-sm text-gray-600">{{ $summary }}</div>
                                <div class="flex flex-wrap gap-2 mt-2">
                                    @if (! empty($meta['network']))
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-gradient-to-r from-purple-50 to-purple-100 text-purple-700 border border-purple-200">{{ $meta['network'] }}</span>
                                    @endif
                                    @if (! empty($meta['size']))
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-gradient-to-r from-gray-100 to-gray-200 text-gray-700 border border-gray-300">{{ $meta['size'] }}</span>
                                    @endif
                                    @if (! empty($meta['validity']))
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-gradient-to-r from-green-50 to-green-100 text-green-700 border border-green-200">{{ $meta['validity'] }}</span>
                                    @endif
                                    @if (! empty($meta['tag']))
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-gradient-to-r from-amber-50 to-amber-100 text-amber-700 border border-amber-200">{{ $meta['tag'] }}</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900">GHS {{ number_format($product->price, 2) }}</td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                @if ($product->is_active)
                                    <x-badge variant="completed" size="sm">Active</x-badge>
                                @else
                                    <x-badge variant="pending" size="sm">Inactive</x-badge>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex items-center justify-end gap-3">
                                    <x-button href="{{ route('vendor.products.edit', $product->id) }}" variant="outline" size="sm">
                                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                        Edit
                                    </x-button>
                                    <form method="POST" action="{{ route('vendor.products.destroy', $product->id) }}" onsubmit="return confirm('Delete this product? This action cannot be undone.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="inline-flex items-center px-3 py-2 border border-red-200 text-red-700 text-xs font-semibold rounded-lg bg-red-50 hover:bg-red-100 transition-all">
                                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                            Delete
                                        </button>
                                    </form>
                                </div>
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
    </div>
</x-vendor-layout>
@endsection
