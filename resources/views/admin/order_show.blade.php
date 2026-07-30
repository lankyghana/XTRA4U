@extends('layouts.admin')

@section('content')
<x-admin-layout title="Order #{{ $order->id }}" subtitle="Order details and fulfillment status" active="orders">
    <div class="space-y-6">

        {{-- Flash messages --}}
        @if (session('success'))
            <div class="rounded-md bg-green-50 border border-green-200 p-4 flex items-center gap-3">
                <svg class="h-5 w-5 text-green-500 shrink-0" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd"/>
                </svg>
                <p class="text-sm font-medium text-green-700">{{ session('success') }}</p>
            </div>
        @endif

        @if (session('error'))
            <div class="rounded-md bg-red-50 border border-red-200 p-4 flex items-center gap-3">
                <svg class="h-5 w-5 text-red-500 shrink-0" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm-1.293-7.293a1 1 0 011.414-1.414L10 10.586l1.293-1.293a1 1 0 111.414 1.414L11.414 12l1.293 1.293a1 1 0 01-1.414 1.414L10 13.414l-1.293 1.293a1 1 0 01-1.414-1.414L8.586 12 7.293 10.707z" clip-rule="evenodd"/>
                </svg>
                <p class="text-sm font-medium text-red-700">{{ session('error') }}</p>
            </div>
        @endif

        {{-- Order Details --}}
        <div class="bg-white shadow-sm rounded-lg p-6">
            <h3 class="text-lg font-semibold text-gray-900">Order Details</h3>
            <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <p class="text-sm text-gray-500">Order ID</p>
                    <p class="font-semibold text-gray-900">#{{ $order->id }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Vendor</p>
                    <p class="font-semibold text-gray-900">{{ $order->vendor->name ?? 'N/A' }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Service</p>
                    <p class="font-semibold text-gray-900">{{ $order->display_service_name }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Amount Paid</p>
                    <p class="font-semibold text-gray-900">₵{{ number_format($order->amount_paid, 2) }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Payment Status</p>
                    <p class="font-semibold text-gray-900">{{ ucfirst($order->payment_status ?? 'N/A') }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Current Status</p>
                    @php
                        $statusColors = [
                            'Pending'    => 'bg-yellow-100 text-yellow-800',
                            'Processing' => 'bg-blue-100 text-blue-800',
                            'Completed'  => 'bg-green-100 text-green-800',
                            'Failed'     => 'bg-red-100 text-red-800',
                            'Cancelled'  => 'bg-red-100 text-red-800',
                            'Refunded'   => 'bg-purple-100 text-purple-800',
                            'On Hold'    => 'bg-orange-100 text-orange-800',
                            'Verifying'  => 'bg-indigo-100 text-indigo-800',
                        ];
                        $colorClass = $statusColors[$order->status] ?? 'bg-gray-100 text-gray-800';
                    @endphp
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $colorClass }}">
                        {{ $order->status ?? 'N/A' }}
                    </span>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Date</p>
                    <p class="font-semibold text-gray-900">{{ $order->created_at?->format('Y-m-d H:i:s') }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Payment Reference</p>
                    <p class="font-semibold text-gray-900 break-all">{{ $order->payment_reference ?? 'N/A' }}</p>
                </div>
            </div>
        </div>

        {{-- Admin: Change Order Status --}}
        <div class="bg-white shadow-sm rounded-lg p-6">
            <h3 class="text-lg font-semibold text-gray-900">Change Order Status</h3>
            <p class="mt-1 text-sm text-gray-500">
                You can update this order's status at any time, including after it has been completed.
            </p>

            <form method="POST" action="{{ route('admin.orders.update', $order) }}" class="mt-4 flex flex-col sm:flex-row items-start sm:items-end gap-4">
                @csrf
                @method('PUT')

                <div class="w-full sm:w-72">
                    <label for="status" class="block text-sm font-medium text-gray-700 mb-1">New Status</label>
                    <select id="status" name="status"
                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm py-2 px-3 border">
                        @foreach ([
                            'Pending'    => 'Pending',
                            'Processing' => 'Processing',
                            'Verifying'  => 'Verifying',
                            'On Hold'    => 'On Hold',
                            'Completed'  => 'Completed',
                            'Refunded'   => 'Refunded',
                            'Cancelled'  => 'Cancelled',
                            'Failed'     => 'Failed',
                        ] as $value => $label)
                            <option value="{{ $value }}" {{ $order->status === $value ? 'selected' : '' }}>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                    @error('status')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition-colors">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                        <path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"/>
                    </svg>
                    Update Status
                </button>
            </form>
        </div>

        {{-- Allocated Pins only apply to Result Checker orders, not regular bundle orders --}}
    </div>
</x-admin-layout>
@endsection
