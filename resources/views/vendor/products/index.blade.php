@extends('layouts.vendor')

@section('title', 'Manage Products - XTRA4U')

{{--
    Visual redesign only — every route()/form action, the $meta decoding
    logic, and the DELETE confirm() dialog are unchanged. x-badge/x-button
    are shared with admin, so left as-is; only this page's own markup and
    the tag-chip colours (now on the brand-violet accent) changed.
--}}
@section('content')
@php use Illuminate\Support\Str; @endphp
<x-vendor-layout :vendor="$vendor" title="Products" subtitle="Manage the services you offer on XTRA4U" active="products">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
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
        <div class="mb-6 flex items-center gap-3 bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span class="text-sm">{{ session('success') }}</span>
        </div>
    @endif

    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Name</th>
                        <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Price</th>
                        <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
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
                        <tr class="hover:bg-violet-50/40 transition-colors duration-150">
                            <td class="px-6 py-4">
                                <div class="text-sm font-semibold text-gray-900">{{ $product->name }}</div>
                                <div class="text-sm text-gray-500 mt-0.5">{{ $summary }}</div>
                                <div class="flex flex-wrap gap-1.5 mt-2">
                                    @if (! empty($meta['network']))
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-violet-50 text-brand-violet-deep border border-violet-100">{{ $meta['network'] }}</span>
                                    @endif
                                    @if (! empty($meta['size']))
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-700 border border-gray-200">{{ $meta['size'] }}</span>
                                    @endif
                                    @if (! empty($meta['validity']))
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-green-50 text-green-700 border border-green-200">{{ $meta['validity'] }}</span>
                                    @endif
                                    @if (! empty($meta['tag']))
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-amber-50 text-amber-700 border border-amber-200">{{ $meta['tag'] }}</span>
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
                                <div class="flex items-center justify-end gap-2">
                                    <x-button href="{{ route('product.edit', $product->id) }}" variant="outline" size="sm">
                                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                        Edit
                                    </x-button>

                                    <form action="{{ route('vendor.products.destroy', $product) }}" method="POST" onsubmit="return confirm('Delete this product? This cannot be undone.');">
                                        @csrf
                                        @method('DELETE')
                                        <x-button type="submit" variant="danger" size="sm">
                                            Delete
                                        </x-button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-16 text-center">
                                <svg class="w-10 h-10 mx-auto text-gray-300 mb-3" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10" />
                                </svg>
                                <p class="text-sm text-gray-500">You have not added any products yet.</p>
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
