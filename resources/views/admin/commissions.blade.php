@extends('layouts.admin')

@section('title', 'Commissions - XTRA4U Admin')

@section('content')
<x-admin-layout title="Commissions" subtitle="Overview of platform earnings" active="reports">
    <div class="max-w-2xl">
        <x-card>
            <div class="px-6 py-5">
                <p class="text-sm uppercase tracking-wide text-gray-500">Total Commission Earned</p>
                <p class="mt-2 text-3xl font-semibold text-gray-900">₵{{ number_format($commissions, 2) }}</p>
                <p class="mt-1 text-sm text-gray-500">Calculated across all vendor orders.</p>
            </div>
        </x-card>
    </div>
</x-admin-layout>
@endsection
