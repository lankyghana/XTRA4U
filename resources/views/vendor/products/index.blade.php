@extends('layouts.vendor')

@section('title', 'Manage Products - XTRA4U')

@section('content')
@php use Illuminate\Support\Str; @endphp
<x-vendor-layout title="Products" subtitle="Manage the services you offer on XTRA4U" active="products">
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
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900">{{ $product->name }}</div>
                                <div class="text-sm text-gray-500">{{ Str::limit($product->description, 60) }}</div>
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
