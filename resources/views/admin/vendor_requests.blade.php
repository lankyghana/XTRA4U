@extends('layouts.admin')

@section('title', 'Vendor Requests - XTRA4U Admin')

@section('content')
<x-admin-layout title="Vendor Requests" subtitle="Review pending vendor applications" active="vendors">
    <x-card>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Phone</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($pendingVendors as $vendor)
                        <tr>
                            <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $vendor->name }}</td>
                            <td class="px-6 py-4 text-sm text-gray-500">{{ $vendor->email }}</td>
                            <td class="px-6 py-4 text-sm text-gray-500">{{ $vendor->phone_number }}</td>
                            <td class="px-6 py-4 text-sm">
                                <form method="POST" action="{{ route('admin.vendor.approve', $vendor->id) }}">
                                    @csrf
                                    <button type="submit" class="inline-flex items-center justify-center rounded-lg font-medium shadow-sm transition focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 px-3 py-1.5 text-xs bg-[#00942C] text-white hover:bg-[#009633] focus:ring-[#00942C]">
                                        Approve
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-4 text-center text-sm text-gray-500">No pending vendor requests.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</x-admin-layout>
@endsection
