@extends('layouts.admin')

@section('title', 'Vendors - Admin Portal')

@section('content')
<x-admin-layout title="Vendors" subtitle="Review, approve, suspend, or remove vendor accounts" active="vendors">
    <div class="space-y-4">
        <div class="grid grid-cols-3 gap-3 sm:gap-4 lg:max-w-2xl">
            <x-admin.stat label="Total" value="{{ $stats['total'] }}" tone="neutral" />
            <x-admin.stat label="Approved" value="{{ $stats['approved'] }}" tone="success" />
            <x-admin.stat label="Pending" value="{{ $stats['pending'] }}" tone="warning" />
        </div>

        @if (session('status'))
            <div role="status" class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('status') }}</div>
        @endif

        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <x-admin.tabs :items="[
                ['label' => 'All vendors', 'href' => route('admin.vendors.index', array_filter(['q' => $search ?? null])), 'active' => ! $statusFilter],
                ['label' => 'Approved', 'href' => route('admin.vendors.index', array_filter(['status' => 'approved', 'q' => $search ?? null])), 'active' => $statusFilter === 'approved'],
                ['label' => 'Pending / Suspended', 'href' => route('admin.vendors.index', array_filter(['status' => 'pending', 'q' => $search ?? null])), 'active' => $statusFilter === 'pending'],
            ]" />
            <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                <x-admin.filters :action="route('admin.vendors.index')" :search="$search ?? ''" placeholder="Search name, email, phone, code" :hidden="['status' => $statusFilter]" :resetUrl="route('admin.vendors.index', array_filter(['status' => $statusFilter]))" />
                <x-button :href="route('admin.vendors.export', array_filter(['status' => $statusFilter, 'q' => $search ?? null]))" variant="outline" size="sm">Export Contacts</x-button>
            </div>
        </div>

        <x-admin.table :headers="['Vendor', 'Contact', 'Affiliate', 'Status', 'Actions']">
            @forelse ($vendors as $vendor)
                <tr>
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
                        <x-admin.status :status="$vendor->is_approved ? 'approved' : 'pending'" />
                        <div class="mt-1 text-xs text-gray-500">
                            Tier: <span class="font-medium text-gray-700">{{ $vendor->tier?->name ?? '—' }}</span>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium" x-data="{ showAdjust: false, showEdit: false, showTier: false }">
                        <div class="flex flex-wrap justify-end gap-2">
                            {{-- Edit Contact button --}}
                            <button type="button" @click="showEdit = !showEdit; showAdjust = false; showTier = false"
                                class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                Edit Contact
                            </button>
                            <button type="button" @click="showAdjust = !showAdjust; showEdit = false; showTier = false"
                                class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                Adjust Balance
                            </button>
                            @if ($vendor->is_approved)
                                <button type="button" @click="showTier = !showTier; showEdit = false; showAdjust = false"
                                    class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                    Change Tier
                                </button>
                            @endif
                            @if ($vendor->affiliate_vendor_id)
                                <form method="POST" action="{{ route('admin.vendors.disable-affiliate', $vendor) }}" onsubmit="return confirm('Disable this affiliate relationship?');">
                                    @csrf
                                    <button type="submit" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                        Disable Affiliate
                                    </button>
                                </form>
                            @endif
                            @if (! $vendor->is_approved)
                                <form method="POST" action="{{ route('admin.vendors.approve', $vendor) }}">
                                    @csrf
                                    <button type="submit" class="inline-flex items-center justify-center rounded-lg font-medium shadow-sm transition focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 px-3 py-1.5 text-xs bg-[#00942C] text-white hover:bg-[#009633] focus:ring-[#00942C]">
                                        Approve
                                    </button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('admin.vendors.reject', $vendor) }}" onsubmit="return confirm('Suspend this vendor? They will lose access until re-approved.');">
                                    @csrf
                                    <button type="submit" class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs font-medium text-amber-800 hover:bg-amber-100">
                                        Suspend
                                    </button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('admin.vendors.destroy', $vendor) }}" onsubmit="return confirm('Delete this vendor? This cannot be undone.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="rounded-lg border border-red-200 bg-white px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-50">
                                    Delete
                                </button>
                            </form>
                        </div>

                        {{-- ── Edit Contact Panel ── --}}
                        <div class="mt-3 text-left" x-show="showEdit" x-cloak>
                            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 space-y-3">
                                <div class="flex items-center justify-between text-xs text-gray-700 font-semibold">
                                    <span>Edit Contact Details</span>
                                    <button type="button" class="text-gray-500 hover:text-brand-violet-deep" @click="showEdit = false">✕ Close</button>
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
                                            class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-brand-violet focus:border-brand-violet"
                                            placeholder="vendor@example.com"
                                        >
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Phone Number</label>
                                        <input
                                            type="text"
                                            name="phone_number"
                                            value="{{ old('phone_number', $vendor->phone_number) }}"
                                            class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-brand-violet focus:border-brand-violet"
                                            placeholder="024xxxxxxx"
                                        >
                                    </div>
                                    <div class="flex justify-end gap-2 pt-1">
                                        <button type="button" @click="showEdit = false"
                                            class="inline-flex items-center justify-center rounded-lg font-medium shadow-sm transition focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 px-3 py-1.5 text-xs border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 focus:ring-brand-violet">
                                            Cancel
                                        </button>
                                        <button type="submit"
                                            class="inline-flex items-center justify-center rounded-lg font-medium shadow-sm transition focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 px-3 py-1.5 text-xs bg-brand-violet text-white hover:bg-brand-violet-deep focus:ring-brand-violet">
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
                                        <input type="number" name="amount" min="0.01" step="0.01" required class="w-full mt-1 px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-brand-violet focus:border-brand-violet" placeholder="e.g. 50.00">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700">Reason (required)</label>
                                        <textarea name="reason" minlength="5" required class="w-full mt-1 px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-brand-violet focus:border-brand-violet" rows="2" placeholder="Describe why this balance is being reduced"></textarea>
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
                            <div class="bg-violet-50 border border-violet-200 rounded-lg p-4 space-y-3">
                                <div class="flex items-center justify-between text-xs text-brand-violet font-semibold">
                                    <span>Change Vendor Tier</span>
                                    <button type="button" class="text-brand-violet hover:text-brand-violet-deep" @click="showTier = false">✕ Close</button>
                                </div>
                                <p class="text-[11px] text-brand-violet">
                                    Manually overrides the tier, bypassing the qualification queue. Current: <strong>{{ $vendor->tier?->name ?? '—' }}</strong>
                                </p>
                                <form method="POST" action="{{ route('admin.vendors.update-tier', $vendor) }}" class="space-y-2" onsubmit="return confirm('Change this vendor\'s tier?');">
                                    @csrf
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Tier</label>
                                        <select name="tier_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-brand-violet focus:border-brand-violet">
                                            @foreach ($tiers as $tier)
                                                <option value="{{ $tier->id }}" @selected((int) $vendor->tier_id === (int) $tier->id)>
                                                    {{ $tier->name }} ({{ rtrim(rtrim(number_format($tier->discount_value, 2), '0'), '.') }}% discount)
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1">Notes (optional)</label>
                                        <textarea name="notes" maxlength="500" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-brand-violet focus:border-brand-violet" placeholder="Reason for this tier change (recorded in history)"></textarea>
                                    </div>
                                    <div class="flex justify-end gap-2 pt-1">
                                        <button type="button" @click="showTier = false"
                                            class="inline-flex items-center justify-center rounded-lg font-medium shadow-sm transition focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 px-3 py-1.5 text-xs border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 focus:ring-brand-violet">
                                            Cancel
                                        </button>
                                        <button type="submit"
                                            class="inline-flex items-center justify-center rounded-lg font-medium shadow-sm transition focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 px-3 py-1.5 text-xs bg-brand-violet text-white hover:bg-brand-violet-deep focus:ring-brand-violet">
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
                    <td colspan="5"><x-admin.empty title="No vendors found" description="Try a different search or status filter." /></td>
                </tr>
            @endforelse
        </x-admin.table>

        <div class="flex justify-end">
            {{ $vendors->links() }}
        </div>
    </div>
</x-admin-layout>
@endsection
