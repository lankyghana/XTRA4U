@extends('layouts.admin')

@section('content')
<x-admin-layout title="Result Checker PINs" subtitle="Upload and manage PIN inventory" active="result-checkers">
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

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="bg-white shadow-sm rounded-lg p-6 space-y-4">
                <h3 class="text-base font-semibold text-gray-900">Bulk Upload PINs</h3>
                <form action="{{ route('admin.result-checkers.pins.store') }}" method="POST" enctype="multipart/form-data" class="space-y-4">
                    @csrf
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Service</label>
                        <select name="service_id" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                            @foreach($services as $service)
                                <option value="{{ $service->id }}" @selected(old('service_id', $selectedService) == $service->id)>
                                    {{ $service->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">CSV File (serial,pin)</label>
                        <input type="file" name="pins_file" accept=".csv,.txt" class="mt-1 block w-full text-sm text-gray-600">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Or Paste CSV Rows</label>
                        <textarea name="pins_text" rows="6" class="mt-1 w-full rounded-md border-gray-300 shadow-sm" placeholder="serial,pin&#10;1234567890,9876543210">{{ old('pins_text') }}</textarea>
                    </div>

                    <button type="submit" class="inline-flex items-center px-4 py-2 bg-brand-deep-blue text-white rounded-md text-sm font-semibold">
                        Upload PINs
                    </button>
                </form>
            </div>

            <div class="bg-white shadow-sm rounded-lg p-6 space-y-4">
                <h3 class="text-base font-semibold text-gray-900">Filters</h3>
                <form method="GET" action="{{ route('admin.result-checkers.pins.index') }}" class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Service</label>
                        <select name="service_id" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                            <option value="">All</option>
                            @foreach($services as $service)
                                <option value="{{ $service->id }}" @selected($selectedService == $service->id)>
                                    {{ $service->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Status</label>
                        <select name="status" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                            <option value="">All</option>
                            <option value="available" @selected($selectedStatus === 'available')>Unused</option>
                            <option value="sold" @selected($selectedStatus === 'sold')>Used</option>
                            <option value="reserved" @selected($selectedStatus === 'reserved')>Reserved</option>
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <button type="submit" class="inline-flex items-center px-4 py-2 bg-gray-900 text-white rounded-md text-sm font-semibold">
                            Apply Filters
                        </button>
                    </div>
                </form>
            </div>
        </div>

        @php
            $mask = fn ($value) => strlen((string) $value) <= 4
                ? str_repeat('*', strlen((string) $value))
                : str_repeat('*', max(strlen((string) $value) - 4, 0)) . substr((string) $value, -4);
        @endphp

        <x-table :headers="['Service', 'Serial', 'PIN', 'Status', 'Order', 'Uploaded']">
            @forelse ($pins as $pin)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 text-sm text-gray-900">{{ $pin->service?->name ?? 'N/A' }}</td>
                    <td class="px-6 py-4 text-sm text-gray-900">{{ $mask($pin->serial) }}</td>
                    <td class="px-6 py-4 text-sm text-gray-900">{{ $mask($pin->pin) }}</td>
                    <td class="px-6 py-4 text-sm text-gray-900">
                        {{ $pin->status === 'available' ? 'Unused' : ucfirst($pin->status) }}
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-900">
                        @if($pin->order)
                            #{{ $pin->order->id }}
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ $pin->created_at?->format('Y-m-d') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500">No pins found.</td>
                </tr>
            @endforelse
        </x-table>

        @if($pins->hasPages())
            <div class="flex justify-end pt-2">
                {{ $pins->links() }}
            </div>
        @endif
    </div>
</x-admin-layout>
@endsection
