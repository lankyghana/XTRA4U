@extends('layouts.app')

@section('content')
<div class="max-w-4xl mx-auto mt-10">
    <h2 class="text-2xl font-bold mb-6">All Orders</h2>
    <table class="min-w-full bg-white border">
        <thead>
            <tr>
                <th class="py-2 px-4 border-b">Order ID</th>
                <th class="py-2 px-4 border-b">Vendor</th>
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
                <td class="py-2 px-4 border-b">{{ $order->vendor->name ?? 'N/A' }}</td>
                <td class="py-2 px-4 border-b">{{ $order->service_purchased }}</td>
                <td class="py-2 px-4 border-b">₵{{ number_format($order->amount_paid, 2) }}</td>
                <td class="py-2 px-4 border-b">{{ ucfirst($order->status) }}</td>
                <td class="py-2 px-4 border-b">{{ $order->created_at->format('Y-m-d') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
