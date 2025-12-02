@extends('layouts.admin')

@section('title', 'Vendors - Admin Portal')

@section('content')
<x-admin-layout title="Vendors" subtitle="Review, approve, suspend, or remove vendor accounts" active="vendors">
    <div class="space-y-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 w-full lg:w-auto">
                <div class="bg-white border border-gray-200 rounded-xl px-4 py-3 text-center">
                    <p class="text-xs uppercase text-gray-500">Total</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $stats['total'] }}</p>
                </div>
                <div class="bg-white border border-green-200 rounded-xl px-4 py-3 text-center">
                    <p class="text-xs uppercase text-green-600">Approved</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $stats['approved'] }}</p>
                </div>
                <div class="bg-white border border-yellow-200 rounded-xl px-4 py-3 text-center">
                    <p class="text-xs uppercase text-yellow-600">Pending</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $stats['pending'] }}</p>
                </div>
            </div>
            <div class="text-sm text-gray-500 lg:text-right">
                Keep onboarding decisions responsive across devices.
            </div>
        </div>

        @if (session('status'))
            <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg">
                {{ session('status') }}
            </div>
        @endif

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('admin.vendors.index') }}"
               class="px-3 py-2 rounded-lg text-sm font-medium {{ $statusFilter ? 'text-gray-600 border border-gray-200' : 'bg-brand-deep-blue text-white' }}">
                All Vendors
            </a>
            <a href="{{ route('admin.vendors.index', ['status' => 'approved']) }}"
               class="px-3 py-2 rounded-lg text-sm font-medium {{ $statusFilter === 'approved' ? 'bg-green-100 text-green-800 border border-green-200' : 'text-gray-600 border border-gray-200' }}">
                Approved
            </a>
            <a href="{{ route('admin.vendors.index', ['status' => 'pending']) }}"
               class="px-3 py-2 rounded-lg text-sm font-medium {{ $statusFilter === 'pending' ? 'bg-yellow-100 text-yellow-800 border border-yellow-200' : 'text-gray-600 border border-gray-200' }}">
                Pending / Suspended
            </a>
        </div>

        <x-table :headers="['Vendor', 'Contact', 'Status', 'Actions']">
            @forelse ($vendors as $vendor)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium text-gray-900">{{ $vendor->name }}</div>
                        <div class="text-sm text-gray-500">Registered {{ $vendor->created_at?->diffForHumans() }}</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm text-gray-900">{{ $vendor->email }}</div>
                        <div class="text-sm text-gray-500">{{ $vendor->phone_number }}</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        @if ($vendor->is_approved)
                            <x-badge variant="completed">Approved</x-badge>
                        @else
                            <x-badge variant="pending">Pending</x-badge>
                        @endif
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <div class="flex flex-wrap justify-end gap-2">
                            @if (! $vendor->is_approved)
                                <form method="POST" action="{{ route('admin.vendors.approve', $vendor) }}">
                                    @csrf
                                    <button type="submit" class="px-3 py-1.5 rounded-lg bg-green-600 text-white hover:bg-green-700 text-xs font-semibold">
                                        Approve
                                    </button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('admin.vendors.reject', $vendor) }}">
                                    @csrf
                                    <button type="submit" class="px-3 py-1.5 rounded-lg bg-yellow-100 text-yellow-800 hover:bg-yellow-200 text-xs font-semibold">
                                        Suspend
                                    </button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('admin.vendors.destroy', $vendor) }}" onsubmit="return confirm('Delete this vendor? This cannot be undone.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="px-3 py-1.5 rounded-lg bg-red-100 text-red-700 hover:bg-red-200 text-xs font-semibold">
                                    Delete
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="px-6 py-4 text-center text-sm text-gray-500">No vendors found for this filter.</td>
                </tr>
            @endforelse
        </x-table>

        <div class="flex justify-end">
            {{ $vendors->links() }}
        </div>
    </div>
</x-admin-layout>
@endsection
