@extends('layouts.app')

@section('content')
<div class="max-w-xl mx-auto mt-10">
    <h2 class="text-2xl font-bold mb-6">Total Commissions</h2>
    <div class="bg-white p-6 rounded shadow">
        <p class="text-lg">Total Commission Earned: <span class="font-bold text-green-700">₵{{ number_format($commissions, 2) }}</span></p>
    </div>
</div>
@endsection
