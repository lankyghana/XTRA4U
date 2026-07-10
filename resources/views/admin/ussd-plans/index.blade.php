@extends('layouts.admin')

@section('title', 'USSD Plans - XTRA4U Admin')
@section('description', 'Manage USSD subscription plans available to vendors')

@section('content')
<x-admin-layout title="USSD Plans" subtitle="Subscription plans vendors purchase to enable their USSD channel" active="ussd-plans">
    <x-slot name="actions">
        <a href="{{ route('admin.ussd-plans.create') }}"
           class="inline-flex items-center gap-1.5 px-4 py-2 bg-brand-deep-blue text-white text-sm font-semibold rounded-lg hover:bg-brand-bright-blue transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            New Plan
        </a>
    </x-slot>

    @if (session('success'))
        <div class="mb-6 rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800 flex items-center gap-2">
            <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
            </svg>
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-6 rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">
            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    @if (! $baseCode)
        <div class="mb-6 rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
            No USSD base code is configured, so vendor codes cannot be generated.
            <a href="{{ route('admin.settings.ussd') }}" class="font-semibold underline">Set one in USSD Settings</a>.
        </div>
    @endif

    @if ($plans->isNotEmpty())
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-xl border border-gray-200 px-4 py-3">
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Total Plans</p>
                <p class="text-2xl font-bold text-gray-900 mt-1">{{ $plans->count() }}</p>
            </div>
            <div class="bg-white rounded-xl border border-gray-200 px-4 py-3">
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Active</p>
                <p class="text-2xl font-bold text-green-600 mt-1">{{ $plans->where('is_active', true)->count() }}</p>
            </div>
            <div class="bg-white rounded-xl border border-gray-200 px-4 py-3">
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Live Subscriptions</p>
                <p class="text-2xl font-bold text-gray-900 mt-1">{{ $plans->sum('live_subscriptions_count') }}</p>
            </div>
            <div class="bg-white rounded-xl border border-gray-200 px-4 py-3">
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Sold All Time</p>
                <p class="text-2xl font-bold text-gray-900 mt-1">{{ $plans->sum('total_subscriptions_count') }}</p>
            </div>
        </div>
    @endif

    <div class="space-y-3">
        @forelse ($plans as $plan)
            <div x-data="{ confirmDelete: false }"
                 class="bg-white rounded-xl border border-gray-200 border-l-4 {{ $plan->is_active ? 'border-l-green-500' : 'border-l-gray-300' }} shadow-sm overflow-hidden">

                <div class="px-5 py-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-sm font-bold text-gray-900">{{ $plan->name }}</h3>
                            <span class="px-2 py-0.5 rounded-full text-xs font-semibold {{ $plan->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' }}">
                                {{ $plan->is_active ? 'Active' : 'Inactive' }}
                            </span>
                            @if ($plan->live_subscriptions_count > 0)
                                <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-blue-100 text-blue-700">
                                    {{ $plan->live_subscriptions_count }} live
                                </span>
                            @endif
                        </div>

                        @if ($plan->description)
                            <p class="mt-1 text-xs text-gray-500">{{ $plan->description }}</p>
                        @endif

                        <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-600">
                            <span><span class="font-semibold text-gray-900">GHS {{ number_format($plan->price, 2) }}</span></span>
                            <span>{{ number_format($plan->included_sessions) }} sessions</span>
                            <span>{{ $plan->duration_days }} {{ Str::plural('day', $plan->duration_days) }}</span>
                            <span class="font-mono text-gray-500">{{ $baseCode ?: '*203*' }}{{ $plan->extension_code }}*&lt;id&gt;#</span>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 flex-shrink-0">
                        <form action="{{ route('admin.ussd-plans.toggle-active', $plan) }}" method="POST">
                            @csrf
                            @method('PATCH')
                            <button type="submit"
                                    class="px-3 py-1.5 text-xs font-semibold rounded-lg border {{ $plan->is_active ? 'border-gray-300 text-gray-700 hover:bg-gray-50' : 'border-green-300 text-green-700 hover:bg-green-50' }}">
                                {{ $plan->is_active ? 'Deactivate' : 'Activate' }}
                            </button>
                        </form>

                        <a href="{{ route('admin.ussd-plans.edit', $plan) }}"
                           class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50">
                            Edit
                        </a>

                        @if ($plan->live_subscriptions_count === 0)
                            <button type="button" @click="confirmDelete = ! confirmDelete"
                                    class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-red-300 text-red-700 hover:bg-red-50">
                                Delete
                            </button>
                        @else
                            <span class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-gray-200 text-gray-400 cursor-not-allowed"
                                  title="Vendors hold live subscriptions on this plan">
                                Delete
                            </span>
                        @endif
                    </div>
                </div>

                <div x-show="confirmDelete" x-cloak class="px-5 py-3 bg-red-50 border-t border-red-100 flex items-center justify-between gap-3">
                    <p class="text-xs text-red-800">Delete &ldquo;{{ $plan->name }}&rdquo;? Its extension code stays reserved.</p>
                    <div class="flex items-center gap-2">
                        <button type="button" @click="confirmDelete = false"
                                class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-gray-300 bg-white text-gray-700">
                            Cancel
                        </button>
                        <form action="{{ route('admin.ussd-plans.destroy', $plan) }}" method="POST">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-red-600 text-white hover:bg-red-700">
                                Confirm Delete
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        @empty
            <div class="bg-white rounded-xl border border-gray-200 px-6 py-12 text-center">
                <p class="text-sm font-medium text-gray-900">No USSD plans yet</p>
                <p class="mt-1 text-sm text-gray-500">Create a plan so vendors can subscribe to the USSD channel.</p>
                <a href="{{ route('admin.ussd-plans.create') }}"
                   class="mt-4 inline-flex items-center px-4 py-2 bg-brand-deep-blue text-white text-sm font-semibold rounded-lg hover:bg-brand-bright-blue">
                    Create the first plan
                </a>
            </div>
        @endforelse
    </div>
</x-admin-layout>
@endsection
