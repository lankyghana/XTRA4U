@extends('layouts.vendor')

@section('title', 'Developer Access - XTRA4U')

@section('content')
<x-vendor-layout title="Developer Access" subtitle="Applications that want to integrate with your store through the XTRA4U API" active="developer-access">
    @if(session('status'))<div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('status') }}</div>@endif

    <div class="bg-white rounded-xl border border-gray-200">
        <p class="px-4 py-3 text-sm text-gray-600 border-b border-gray-200">Approving lets the application see and sell <b>your store's</b> products through the API. Orders are priced, paid and settled exactly like normal storefront orders; the application earns nothing extra and sees none of your financial or contact details. You can decline or withdraw at any time.</p>
        @forelse($applications as $a)
            <div class="px-4 py-4 border-b border-gray-100 flex items-center justify-between gap-3 flex-wrap text-sm">
                <div>
                    <div class="font-medium">{{ $a->name }} <span class="text-xs rounded-full bg-gray-100 px-2 py-0.5">{{ $a->vendor_link_status }}</span></div>
                    <div class="text-xs text-gray-500">By {{ $a->developer?->name }}{{ $a->developer?->company ? ' ('.$a->developer->company.')' : '' }} · requested {{ $a->vendor_link_requested_at?->toDayDateTimeString() }}</div>
                    @if($a->description)<div class="text-xs text-gray-500 mt-1">{{ $a->description }}</div>@endif
                </div>
                <div class="flex gap-2">
                    @if($a->vendor_link_status !== 'approved')
                        <form method="POST" action="{{ route('vendor.developer-access.approve', $a->id) }}">@csrf<button class="rounded-lg bg-[#00942C] text-white px-3 py-1.5 text-xs">Approve</button></form>
                    @endif
                    @if($a->vendor_link_status !== 'rejected')
                        <form method="POST" action="{{ route('vendor.developer-access.reject', $a->id) }}">@csrf<button class="rounded-lg border border-red-300 text-red-700 px-3 py-1.5 text-xs">{{ $a->vendor_link_status === 'approved' ? 'Withdraw approval' : 'Decline' }}</button></form>
                    @endif
                </div>
            </div>
        @empty
            <p class="px-4 py-8 text-center text-sm text-gray-500">No applications have asked to integrate with your store.</p>
        @endforelse
    </div>
</x-vendor-layout>
@endsection
