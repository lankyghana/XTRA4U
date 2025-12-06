@extends('layouts.admin')

@section('content')
<div class="container mx-auto max-w-lg py-8">
    <h2 class="text-2xl font-bold mb-6">Paystack API Configuration</h2>
    @if(session('success'))
        <div class="bg-green-100 text-green-800 px-4 py-2 rounded mb-4">{{ session('success') }}</div>
    @endif
    <form method="POST" action="{{ route('admin.paystack-config.update') }}" class="space-y-6">
        @csrf
        <div>
            <label for="public_key" class="block font-semibold mb-1">Public Key</label>
            <input type="text" name="public_key" id="public_key" value="{{ old('public_key', $publicKey) }}" class="w-full border rounded px-3 py-2" required>
        </div>
        <div>
            <label for="secret_key" class="block font-semibold mb-1">Secret Key</label>
            <input type="text" name="secret_key" id="secret_key" value="{{ old('secret_key', $secretKey) }}" class="w-full border rounded px-3 py-2" required>
        </div>
        <div>
            <label for="payment_url" class="block font-semibold mb-1">Payment URL</label>
            <input type="url" name="payment_url" id="payment_url" value="{{ old('payment_url', $paymentUrl) }}" class="w-full border rounded px-3 py-2" required>
        </div>
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded font-bold">Update Paystack Keys</button>
    </form>
</div>
@endsection
