@extends('layouts.app')

@section('content')
<div class="min-h-screen bg-gray-100 py-8">
    <div class="max-w-2xl mx-auto bg-white rounded-lg shadow-md p-8">
        <h2 class="text-2xl font-bold mb-4 text-center">{{ $vendor->name }}'s Store</h2>
        <div class="mb-6">
            <p class="font-semibold">Contact: {{ $vendor->email }} | {{ $vendor->phone_number }}</p>
        </div>
        <h3 class="text-xl font-bold mb-2">Products</h3>
        <ul>
            @forelse ($products as $product)
                <li class="mb-4 p-4 border rounded">
                    <div class="font-bold">{{ $product->name }}</div>
                    <div>{{ $product->description }}</div>
                    <div class="text-blue-600 font-bold">GHS {{ number_format($product->price, 2) }}</div>
                </li>
            @empty
                <li>No products available.</li>
            @endforelse
        </ul>
    </div>
</div>
@endsection
