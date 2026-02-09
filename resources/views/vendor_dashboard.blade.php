@extends('layouts.app')

@section('content')
<div class="min-h-screen bg-gray-100 py-6">
    <div class="w-full max-w-2xl mx-auto bg-white rounded-lg shadow-md p-8">
        <h2 class="text-2xl font-bold mb-6 text-center">Vendor Dashboard</h2>
        <div class="mb-4">
            <p class="font-semibold">Welcome, {{ $vendor->name }}!</p>
            <p>Email: {{ $vendor->email }}</p>
            <p>Phone: {{ $vendor->phone_number }}</p>
            <p>Status: <span class="font-bold text-green-600">Approved</span></p>
        </div>
        <div class="mb-6">
            <h3 class="text-lg font-semibold mb-2">Sales Summary</h3>
            <p>Total Sales: <span class="font-bold">₵{{ number_format($totalSales, 2) }}</span></p>
        </div>
        <div class="mb-6">
            <h3 class="text-lg font-semibold mb-2">Earnings Summary</h3>
            <p>Total Earnings (after 2% commission): <span class="font-bold text-green-700">₵{{ number_format($totalEarnings, 2) }}</span></p>
        </div>
        <div class="mb-6">
            <h3 class="text-lg font-semibold mb-2">Order List</h3>
            <table class="min-w-full bg-white border">
                <thead>
                    <tr>
                        <th class="py-2 px-4 border-b">Order ID</th>
                        <th class="py-2 px-4 border-b">Service</th>
                        <th class="py-2 px-4 border-b">Amount Paid</th>
                        <th class="py-2 px-4 border-b">Status</th>
                        <th class="py-2 px-4 border-b">Date</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($orders as $order)
                    <tr>
                        <td class="py-2 px-4 border-b">{{ $order->id }}</td>
                        <td class="py-2 px-4 border-b">{{ $order->display_service_name }}</td>
                        <td class="py-2 px-4 border-b">₵{{ number_format($order->amount_paid, 2) }}</td>
                        <td class="py-2 px-4 border-b">{{ ucfirst($order->status) }}</td>
                        <td class="py-2 px-4 border-b">{{ $order->created_at->format('Y-m-d') }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mb-6">
            <h3 class="text-lg font-semibold mb-2">Product Management</h3>
            <a href="{{ route('product.create') }}" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600 mb-4 inline-block">Add New Product</a>
            <table class="min-w-full bg-white border">
                <thead>
                    <tr>
                        <th class="py-2 px-4 border-b">Product Name</th>
                        <th class="py-2 px-4 border-b">Price</th>
                        <th class="py-2 px-4 border-b">Status</th>
                        <th class="py-2 px-4 border-b">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($products as $product)
                    <tr>
                        <td class="py-2 px-4 border-b">{{ $product->name }}</td>
                        <td class="py-2 px-4 border-b">₵{{ number_format($product->price, 2) }}</td>
                        <td class="py-2 px-4 border-b">{{ $product->is_active ? 'Active' : 'Inactive' }}</td>
                        <td class="py-2 px-4 border-b">
                            <a href="{{ route('product.edit', $product->id) }}" class="text-blue-600 hover:underline">Edit</a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mb-6">
            <h3 class="text-lg font-semibold mb-2">Your Store Link</h3>
            <a href="{{ $storeLink }}" class="text-indigo-600 hover:underline" target="_blank">{{ $storeLink }}</a>
        </div>
    </div>
</div>
@endsection
