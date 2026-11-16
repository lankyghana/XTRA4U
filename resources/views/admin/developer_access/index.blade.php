@extends('layouts.admin')

@section('title', 'Developer Access - XTRA4U Admin')

@section('content')
<x-admin-layout title="Developer Access" subtitle="Review production access requests from API applications" active="developer-access">
    @if(session('status'))<div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first() }}</div>@endif

    <x-card>
        <h2 class="text-sm font-semibold text-gray-900 mb-3">Pending requests</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50"><tr>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Application</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Developer</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Requested</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Decision</th>
                </tr></thead>
                <tbody class="bg-white divide-y divide-gray-200">
                @forelse($requested as $a)
                    <tr>
                        <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ $a->name }}<div class="text-xs text-gray-500">{{ $a->description }}</div></td>
                        <td class="px-4 py-3 text-sm text-gray-500">{{ $a->developer?->name }}<div class="text-xs">{{ $a->developer?->email }} {{ $a->developer?->company ? '· '.$a->developer->company : '' }}</div></td>
                        <td class="px-4 py-3 text-sm text-gray-500">{{ $a->vendor_id ? 'Vendor-bound: '.$a->vendor?->name.' ('.$a->vendor_link_status.')' : 'Marketplace' }}</td>
                        <td class="px-4 py-3 text-sm text-gray-500">{{ $a->production_requested_at?->toDayDateTimeString() }}</td>
                        <td class="px-4 py-3 text-sm">
                            <form method="POST" action="{{ route('admin.developer-access.approve', $a->id) }}" class="inline">@csrf
                                <button class="rounded-lg bg-[#00942C] text-white px-3 py-1.5 text-xs">Approve</button></form>
                            <form method="POST" action="{{ route('admin.developer-access.reject', $a->id) }}" class="inline-flex gap-1 mt-1">@csrf
                                <input name="note" required maxlength="500" placeholder="Reason (shown to developer)" class="rounded border border-gray-300 px-2 py-1 text-xs">
                                <button class="rounded-lg border border-red-300 text-red-700 px-3 py-1.5 text-xs">Reject</button></form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-4 text-center text-sm text-gray-500">No pending production requests.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    <x-card class="mt-6">
        <h2 class="text-sm font-semibold text-gray-900 mb-3">Approved (latest 50)</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200"><tbody class="bg-white divide-y divide-gray-200">
                @forelse($approved as $a)
                    <tr>
                        <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ $a->name }}<div class="text-xs text-gray-500">{{ $a->developer?->email }}</div></td>
                        <td class="px-4 py-3 text-sm text-gray-500">approved {{ $a->production_approved_at?->toDayDateTimeString() }} by {{ $a->production_reviewed_by }}</td>
                        <td class="px-4 py-3 text-sm">
                            <form method="POST" action="{{ route('admin.developer-access.withdraw', $a->id) }}" class="inline-flex gap-1">@csrf
                                <input name="note" required maxlength="500" placeholder="Reason" class="rounded border border-gray-300 px-2 py-1 text-xs">
                                <button class="rounded-lg border border-red-300 text-red-700 px-3 py-1.5 text-xs">Withdraw</button></form>
                        </td>
                    </tr>
                @empty
                    <tr><td class="px-4 py-4 text-center text-sm text-gray-500">None yet.</td></tr>
                @endforelse
            </tbody></table>
        </div>
    </x-card>
</x-admin-layout>
@endsection
