@extends('layouts.vendor')

@section('title', 'Result Checkers - XTRA4U')

@section('content')
<x-vendor-layout :vendor="$vendor" title="Result Checkers" subtitle="Manage availability and profit margins" active="result-checkers">
    <div class="space-y-6">
        @if(session('success'))
            <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg">
                {{ session('success') }}
            </div>
        @endif

        @if($errors->any())
            <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg">
                {{ $errors->first() }}
            </div>
        @endif

        <form action="{{ route('vendor.result-checkers.update') }}" method="POST" class="bg-white shadow-sm rounded-lg p-6 space-y-4">
            @csrf
            <div class="flex items-center justify-between">
                <h3 class="text-base font-semibold text-gray-900">Service Settings</h3>
                <a href="{{ route('vendor.result-checkers.orders.index') }}" class="text-sm text-brand-deep-blue font-semibold">View Orders</a>
            </div>

            <x-table :headers="['Service', 'Base Price', 'Profit (GHS)', 'Active']">
                @forelse ($services as $service)
                    @php
                        $setting = $settings->get($service->id);
                    @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 text-sm text-gray-900 font-medium">{{ $service->name }}</td>
                        <td class="px-6 py-4 text-sm text-gray-700">GHS {{ number_format((float) ($service->base_price ?? 0), 2) }}</td>
                        <td class="px-6 py-4 text-sm text-gray-700">
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                name="settings[{{ $service->id }}][profit_amount]"
                                value="{{ old("settings.{$service->id}.profit_amount", $setting?->profit_amount ?? 0) }}"
                                class="w-28 rounded-md border-gray-300 shadow-sm"
                            >
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-700">
                            <input type="checkbox" name="settings[{{ $service->id }}][is_active]" value="1" @checked(old("settings.{$service->id}.is_active", $setting?->is_active))>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-6 py-4 text-center text-sm text-gray-500">No result checker services found.</td>
                    </tr>
                @endforelse
            </x-table>

            <button type="submit" class="inline-flex items-center px-4 py-2 bg-brand-deep-blue text-white rounded-md text-sm font-semibold">
                Save Settings
            </button>
        </form>
    </div>
</x-vendor-layout>
@endsection
