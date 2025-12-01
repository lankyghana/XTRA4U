@extends('layouts.app')

@section('content')
<div class="max-w-3xl mx-auto mt-10">
    <h2 class="text-2xl font-bold mb-6">All Transactions</h2>
    <table class="min-w-full bg-white border">
        <thead>
            <tr>
                <th class="py-2 px-4 border-b">Transaction ID</th>
                <th class="py-2 px-4 border-b">Order ID</th>
                <th class="py-2 px-4 border-b">Amount</th>
                <th class="py-2 px-4 border-b">Commission</th>
                <th class="py-2 px-4 border-b">Vendor Earnings</th>
            </tr>
        </thead>
        <tbody>
            @foreach($transactions as $transaction)
            <tr>
                <td class="py-2 px-4 border-b">{{ $transaction->id }}</td>
                <td class="py-2 px-4 border-b">{{ $transaction->order_id }}</td>
                <td class="py-2 px-4 border-b">₵{{ number_format($transaction->amount, 2) }}</td>
                <td class="py-2 px-4 border-b">₵{{ number_format($transaction->commission_deducted, 2) }}</td>
                <td class="py-2 px-4 border-b">₵{{ number_format($transaction->vendor_earnings, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
