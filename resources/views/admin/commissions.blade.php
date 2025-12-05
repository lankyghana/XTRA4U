@extends('layouts.admin')

@section('title', 'Commissions - XTRA4U Admin')

@section('content')
<x-admin-layout title="Commissions" subtitle="Overview of platform earnings" active="reports">
    <div class="max-w-2xl">
        <div class="bg-gradient-to-br from-brand-deep-blue to-brand-green rounded-2xl shadow-xl overflow-hidden transform hover:scale-105 transition-transform duration-200">
            <div class="px-8 py-10">
                <div class="flex items-center space-x-4 mb-4">
                    <div class="w-16 h-16 bg-white/20 backdrop-blur-sm rounded-xl flex items-center justify-center">
                        <svg class="w-8 h-8 text-white" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M4 4a2 2 0 00-2 2v4a2 2 0 002 2V6h10a2 2 0 00-2-2H4zm2 6a2 2 0 012-2h8a2 2 0 012 2v4a2 2 0 01-2 2H8a2 2 0 01-2-2v-4zm6 4a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm uppercase tracking-wide text-blue-100 font-medium">Total Commission Earned</p>
                        <p class="mt-2 text-4xl font-bold text-white">₵{{ number_format($commissions, 2) }}</p>
                    </div>
                </div>
                <p class="mt-4 text-sm text-blue-100">Calculated across all vendor orders and transactions processed on the platform.</p>
            </div>
        </div>
    </div>
</x-admin-layout>
@endsection
