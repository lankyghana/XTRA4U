@extends('layouts.admin')

@section('title', 'USSD Audit Log - XTRA4U Admin')
@section('description', 'Every USSD subscription, payment, and security event')

@section('content')
<x-admin-layout title="USSD Audit Log" subtitle="Subscription, payment, session, and security events" active="ussd-events">

    <form method="GET" action="{{ route('admin.ussd-events.index') }}"
          class="mb-6 bg-white rounded-xl border border-gray-200 p-4 flex flex-wrap items-end gap-4">
        <div class="flex-1 min-w-[200px]">
            <label for="event" class="block text-xs font-medium text-gray-700 mb-1">Event</label>
            <select name="event" id="event" class="block w-full border-gray-300 rounded-lg text-sm">
                <option value="">All events</option>
                @foreach ($eventOptions as $option)
                    <option value="{{ $option }}" {{ ($filters['event'] ?? '') === $option ? 'selected' : '' }}>
                        {{ ucfirst(str_replace('_', ' ', $option)) }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="flex-1 min-w-[200px]">
            <label for="vendor_id" class="block text-xs font-medium text-gray-700 mb-1">Vendor</label>
            <select name="vendor_id" id="vendor_id" class="block w-full border-gray-300 rounded-lg text-sm">
                <option value="">All vendors</option>
                @foreach ($vendors as $vendor)
                    <option value="{{ $vendor->id }}" {{ (string) ($filters['vendor_id'] ?? '') === (string) $vendor->id ? 'selected' : '' }}>
                        {{ $vendor->name }} ({{ $vendor->vendor_code }})
                    </option>
                @endforeach
            </select>
        </div>

        <div class="flex gap-2">
            <button type="submit" class="px-4 py-2 bg-brand-deep-blue text-white text-sm font-semibold rounded-lg hover:bg-brand-bright-blue">
                Filter
            </button>
            <a href="{{ route('admin.ussd-events.index') }}"
               class="px-4 py-2 border border-gray-300 text-gray-700 text-sm font-semibold rounded-lg hover:bg-gray-50">
                Reset
            </a>
        </div>
    </form>

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">When</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Event</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Vendor</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Description</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Actor</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">IP</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($events as $event)
                        @php
                            $isSecurity = in_array($event->event, [
                                \App\Models\UssdSubscriptionEvent::SECURITY_EVENT,
                                \App\Models\UssdSubscriptionEvent::INVALID_USSD_REQUEST,
                                \App\Models\UssdSubscriptionEvent::PAYMENT_FAILED,
                            ], true);
                        @endphp
                        <tr class="{{ $isSecurity ? 'bg-red-50/40' : '' }}">
                            <td class="px-4 py-3 whitespace-nowrap text-gray-600">
                                {{ $event->created_at?->format('d M Y H:i') }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <span class="px-2 py-0.5 rounded-full text-xs font-semibold
                                    {{ $isSecurity ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-700' }}">
                                    {{ str_replace('_', ' ', $event->event) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-gray-900">
                                {{ $event->vendor?->name ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-gray-600 max-w-md">{{ $event->description }}</td>
                            <td class="px-4 py-3 whitespace-nowrap text-gray-500">
                                {{ $event->actor_type ?? '—' }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap font-mono text-xs text-gray-500">
                                {{ $event->ip_address ?? '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-12 text-center text-sm text-gray-500">
                                No USSD events recorded yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $events->links() }}
    </div>
</x-admin-layout>
@endsection
