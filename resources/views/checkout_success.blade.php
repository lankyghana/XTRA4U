@extends('layouts.app')

@section('content')
<div class="min-h-screen flex items-center justify-center bg-green-100">
    <div class="w-full max-w-md bg-white rounded-lg shadow-md p-8 text-center">
        <h2 class="text-2xl font-bold mb-6 text-green-700">Purchase Successful!</h2>
        <p class="mb-4">Your order has been processed and the recipient will receive the service shortly.</p>
        <a href="{{ route('checkout.show') }}" class="inline-block bg-blue-600 text-white py-2 px-4 rounded hover:bg-blue-700">Make Another Purchase</a>
    </div>
</div>
@endsection
