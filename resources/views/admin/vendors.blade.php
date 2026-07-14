@extends('layouts.admin')

@section('title', 'Vendors - Admin Portal')

@section('content')
<x-admin-layout title="Vendors" subtitle="Review, approve, suspend, or remove vendor accounts" active="vendors">
    <div class="space-y-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 w-full lg:w-auto">
                <div class="bg-gradient-to-br from-gray-500 to-gray-600 rounded-xl px-5 py-4 text-center shadow-lg transform hover:scale-105 transition-transform duration-200">
                    <p class="text-xs uppercase text-gray-100 font-medium">Total</p>
                    <p class="text-2xl font-bold text-white mt-1">{{ $stats['total'] }}</p>
                </div>
                <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-xl px-5 py-4 text-center shadow-lg transform hover:scale-105 transition-transform duration-200">
                    <p class="text-xs uppercase text-green-100 font-medium">Approved</p>
                    <p class="text-2xl font-bold text-white mt-1">{{ $stats['approved'] }}</p>
                </div>
                <div class="bg-gradient-to-br from-yellow-500 to-yellow-600 rounded-xl px-5 py-4 text-center shadow-lg transform hover:scale-105 transition-transform duration-200">
                    <p class="text-xs uppercase text-yellow-100 font-medium">Pending</p>
                    <p class="text-2xl font-bold text-white mt-1">{{ $stats['pending'] }}</p>
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

        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-sm font-semibold text-gray-900">Search</h2>
                    <p class="text-xs text-gray-500">Search by name, email, phone, or vendor code.</p>
                </div>
                <form method="GET" action="{{ route('admin.vendors.index') }}" class="flex flex-col sm:flex-row gap-2 w-full sm:w-auto">
                    @if ($statusFilter)
                        <input type="hidden" name="status" value="{{ $statusFilter }}" />
                    @endif
                    <input
                        type="text"
                        name="q"
                        value="{{ $search ?? '' }}"
                        placeholder="e.g. Acme, 024..., VND123AB"
                        class="w-full sm:w-80 border border-gray-300 rounded-lg text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-brand-deep-blue focus:border-brand-deep-blue"
                    />
                    <div class="flex gap-2">
                        <x-button type="submit" variant="primary" class="justify-center">Search</x-button>
                        <x-button href="{{ route('admin.vendors.index', array_filter(['status' => $statusFilter])) }}" variant="outline" class="justify-center">Reset</x-button>
                    </div>
                </form>
            </div>
        </div>

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

        <x-table :headers="['Vendor', 'Contact', 'Affiliate', 'Status', 'Actions']">
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
                        <div class="text-sm text-gray-900">
                            <span class="text-gray-500">Affiliate of:</span>
                            @if ($vendor->affiliateVendor)
                                <span class="font-medium">{{ $vendor->affiliateVendor->name }}</span>
                            @else
                                <span class="text-gray-500">-</span>
                            @endif
                        </div>
                        <div class="text-sm text-gray-500">Affiliates: {{ (int) ($vendor->affiliates_count ?? 0) }}</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        @if ($vendor->is_approved)
                            <x-badge variant="completed">Approved</x-badge>
                        @else
                            <x-badge variant="pending">Pending</x-badge>
                        @endif
                        <div class="mt-1 text-xs text-gray-500">
                            Tier: <span class="font-medium text-gray-700">{{ $vendor->tier?->name ?? '—' }}</span>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium" x-data="{ showAdjust: false, showEdit: false, showTier: false }">
                        <div class="flex flex-wrap justify-end gap-2">
                            {{-- Edit Contact button --}}
                            <button type="button" @click="showEdit = !showEdit; showAdjust = false; showTier = false"
                                class="px-3 py-1.5 rounded-lg bg-blue-50 text-blue-700 hover:bg-blue-100 text-xs font-semibold">
                                Edit Contact
                            </button>
                            <button type="button" @click="showAdjust = !showAdjust; showEdit = false; showTier = false"
                                class="px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700 hover:bg-indigo-100 text-xs font-semibold">
                                Adjust Balance
                            </button>
                            @if ($vendor->is_approved)
                                <button type="button" @click="showTier = !showTier; showEdit = false; showAdjust = false"
                                    class="px-3 py-1.5 rounded-lg bg-purple-50 text-purple-700 hover:bg-purple-100 text-xs font-semibold">
                                    Change Tier
                                </button>
                            @endif
                            @if ($vendor->affiliate_vendor_id)
                                <form method="POST" action="{{ route('admin.vendors.disable-affiliate', $vendor) }}" onsubmit="return confirm('Disable this affiliate relationship?');">
                                    @csrf
                                    <button type="submit" class="px-3 py-1.5 rounded-lg bg-gray-100 text-gray-800 hover:bg-gray-200 text-xs font-semibold">
                                        Disable Affiliate
                                    </button>
                                </form>
                            @endif
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

                        {{-- ── Edit Contact Panel ── --}}
                        <div class="mt-3 text-left" x-show="showEdit" x-cloak>
                            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 space-y-3">
                                <div class="flex items-center justify-between text-xs text-blue-700 font-semibold">
                                    <span>Edit Contact Details</span>
                                    <button type="button" class="text-blue-400 hover:text-blue-600" @click="showEdit = false">✕ Close</button>
                                </div>
                                <form method="POST" action="{{ route('admin.vendors.update', $vendor) }}" class="space-y-3">
                                    @csrf
                                    @method('PATCH')
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Email</label>
                                        <input
                                            type="email"
                                            name="email"
                                            value="{{ old('email', $vendor->email) }}"
                                            required
                                            class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-blue-500 focus:border-blue-500"
                                            placeholder="vendor@example.com"
                                        >
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Phone Number</label>
                                        <input
                                            type="text"
                                            name="phone_number"
                                            value="{{ old('phone_number', $vendor->phone_number) }}"
                                            class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-blue-500 focus:border-blue-500"
                                            placeholder="024xxxxxxx"
                                        >
                                    </div>
                                    <div class="flex justify-end gap-2 pt-1">
                                        <button type="button" @click="showEdit = false"
                                            class="px-3 py-1.5 rounded-md text-xs font-medium border border-gray-300 text-gray-600 hover:bg-gray-100">
                                            Cancel
                                        </button>
                                        <button type="submit"
                                            class="px-4 py-1.5 rounded-md text-xs font-semibold bg-blue-600 text-white hover:bg-blue-700">
                                            Save Changes
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        {{-- ── Adjust Balance Panel ── --}}
                        <div class="mt-3 text-left" x-show="showAdjust" x-cloak>
                            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 space-y-3">
                                <div class="flex items-center justify-between text-xs text-gray-600">
                                    <span>Current balance: <strong>GHS {{ number_format($vendor->wallet_balance, 2) }}</strong></span>
                                    <button type="button" class="text-gray-500 hover:text-gray-700" @click="showAdjust = false">Close</button>
                                </div>
                                <form method="POST" action="{{ route('admin.vendors.adjust-balance', $vendor) }}" class="space-y-2" onsubmit="return confirm('Subtract from this vendor balance? This cannot be undone.');">
                                    @csrf
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700">Amount</label>
                                        <input type="number" name="amount" min="0.01" step="0.01" required class="w-full mt-1 px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-brand-deep-blue focus:border-brand-deep-blue" placeholder="e.g. 50.00">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700">Reason (required)</label>
                                        <textarea name="reason" minlength="5" required class="w-full mt-1 px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-brand-deep-blue focus:border-brand-deep-blue" rows="2" placeholder="Describe why this balance is being reduced"></textarea>
                                    </div>
                                    <p class="text-[11px] text-red-600">This action subtracts funds and cannot be undone.</p>
                                    <div class="flex justify-end">
                                        <x-button type="submit" variant="danger" size="sm">Confirm Adjustment</x-button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        {{-- ── Change Tier Panel ── --}}
                        @if ($vendor->is_approved)
                        <div class="mt-3 text-left" x-show="showTier" x-cloak>
                            <div class="bg-purple-50 border border-purple-200 rounded-lg p-4 space-y-3">
                                <div class="flex items-center justify-between text-xs text-purple-700 font-semibold">
                                    <span>Change Vendor Tier</span>
                                    <button type="button" class="text-purple-400 hover:text-purple-600" @click="showTier = false">✕ Close</button>
                                </div>
                                <p class="text-[11px] text-purple-600">
                                    Manually overrides the tier, bypassing the qualification queue. Current: <strong>{{ $vendor->tier?->name ?? '—' }}</strong>
                                </p>
                                <form method="POST" action="{{ route('admin.vendors.update-tier', $vendor) }}" class="space-y-2" onsubmit="return confirm('Change this vendor\'s tier?');">
                                    @csrf
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Tier</label>
                                        <select name="tier_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-purple-500 focus:border-purple-500">
                                            @foreach ($tiers as $tier)
                                                <option value="{{ $tier->id }}" @selected((int) $vendor->tier_id === (int) $tier->id)>
                                                    {{ $tier->name }} ({{ rtrim(rtrim(number_format($tier->discount_value, 2), '0'), '.') }}% discount)
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Notes (optional)</label>
                                        <textarea name="notes" maxlength="500" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-purple-500 focus:border-purple-500" placeholder="Reason for this tier change (recorded in history)"></textarea>
                                    </div>
                                    <div class="flex justify-end gap-2 pt-1">
                                        <button type="button" @click="showTier = false"
                                            class="px-3 py-1.5 rounded-md text-xs font-medium border border-gray-300 text-gray-600 hover:bg-gray-100">
                                            Cancel
                                        </button>
                                        <button type="submit"
                                            class="px-4 py-1.5 rounded-md text-xs font-semibold bg-purple-600 text-white hover:bg-purple-700">
                                            Save Tier
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-6 py-4 text-center text-sm text-gray-500">No vendors found for this filter.</td>
                </tr>
            @endforelse
        </x-table>

        <div class="flex justify-end">
            {{ $vendors->links() }}
        </div>
    </div>
</x-admin-layout>
@endsection
