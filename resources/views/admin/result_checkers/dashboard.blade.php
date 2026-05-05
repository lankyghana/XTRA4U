@extends('layouts.admin')

@section('content')
<x-admin-layout title="Result Checkers" subtitle="Stock visibility and service totals" active="result-checkers">
    <div class="space-y-6">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">Stock Overview</h2>
            <p class="text-sm text-gray-500">Track available inventory per result checker service.</p>
        </div>

        <x-table :headers="['Service', 'Total Pins', 'Used Pins', 'Remaining Stock']">
            @forelse ($services as $service)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $service->name }}</td>
                    <td class="px-6 py-4 text-sm text-gray-900">{{ $service->total_pins ?? 0 }}</td>
                    <td class="px-6 py-4 text-sm text-gray-900">{{ $service->used_pins ?? 0 }}</td>
                    <td class="px-6 py-4 text-sm font-semibold text-gray-900">{{ $service->available_pins ?? 0 }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="px-6 py-4 text-center text-sm text-gray-500">No result checker services found.</td>
                </tr>
            @endforelse
        </x-table>
    </div>
</x-admin-layout>
@endsection
