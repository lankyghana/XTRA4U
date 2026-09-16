@extends('layouts.vendor')

@section('title', 'Affiliates - XTRA4U')
@section('description', 'Manage your affiliate network and track referrals')

@section('content')
<x-vendor-layout :vendor="$vendor" title="Affiliates" subtitle="Grow your network and track your referrals" active="affiliates">
    <div class="space-y-6">
        <!-- Affiliate Code Card -->
        <div class="bg-brand-violet rounded-xl overflow-hidden">
            <div class="px-6 py-8 text-white">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xl font-bold">Your Affiliate Code</h2>
                    <svg class="w-7 h-7 text-white/70" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                    </svg>
                </div>

                <div class="bg-white/10 rounded-lg p-6 mb-4">
                    <p class="text-sm text-violet-100 mb-2">Share this code with others:</p>
                    <div class="flex items-center justify-between bg-white/10 rounded-lg px-4 py-3">
                        <span class="text-3xl font-mono font-bold tracking-wider">{{ $vendor->vendor_code }}</span>
                        <button
                            onclick="copyToClipboard('{{ $vendor->vendor_code }}')"
                            class="bg-white text-brand-violet hover:bg-violet-50 px-4 py-2 rounded-lg font-semibold transition-colors flex items-center space-x-2"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                            </svg>
                            <span>Copy</span>
                        </button>
                    </div>
                    <p class="text-sm text-violet-100 mt-3">When new vendors use your code during registration, they'll be linked to your network.</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="bg-white/10 rounded-lg p-4">
                        <p class="text-sm text-violet-100">Total Referrals</p>
                        <p class="text-3xl font-bold mt-1">{{ $totalReferrals }}</p>
                    </div>
                    <div class="bg-white/10 rounded-lg p-4">
                        <p class="text-sm text-violet-100">Active Referrals</p>
                        <p class="text-3xl font-bold mt-1">{{ $activeReferrals }}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Join Affiliate Parent Section -->
        @if(!$vendor->affiliate_vendor_id)
        <div class="bg-white rounded-xl border border-gray-200">
            <div class="px-6 py-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-9 h-9 bg-brand-violet-soft rounded-lg flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-brand-violet" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-base font-semibold text-gray-900">Join an Affiliate Network</h2>
                        <p class="text-sm text-gray-500">Enter another vendor's affiliate code to connect and resell their products.</p>
                    </div>
                </div>

                @if(session('affiliate_error'))
                    <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg mb-4 text-sm">
                        {{ session('affiliate_error') }}
                    </div>
                @endif

                @if(session('affiliate_success'))
                    <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg mb-4 text-sm">
                        {{ session('affiliate_success') }}
                    </div>
                @endif

                <form method="POST" action="{{ route('vendor.affiliates.join') }}" class="flex flex-col sm:flex-row gap-3">
                    @csrf
                    <div class="flex-1">
                        <input
                            type="text"
                            name="affiliate_code"
                            placeholder="Enter affiliate code (e.g., VEND123ABC)"
                            required
                            class="w-full px-4 py-3 rounded-lg border border-gray-300 shadow-sm focus:border-brand-violet focus:ring-brand-violet uppercase"
                            style="text-transform: uppercase;"
                        >
                    </div>
                    <x-button type="submit" variant="primary">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                        </svg>
                        Connect
                    </x-button>
                </form>
            </div>
        </div>
        @else
        <!-- Already Connected to Affiliate Parent -->
        <div class="bg-white rounded-xl border border-gray-200">
            <div class="px-6 py-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-9 h-9 bg-green-50 rounded-lg flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <h2 class="text-base font-semibold text-gray-900">Your Affiliate Parent</h2>
                </div>

                <div class="bg-gray-50 rounded-lg p-5 border border-gray-100">
                    <p class="text-sm text-gray-500 mb-1">You are connected to:</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $affiliateParent->business_name ?? $affiliateParent->name }}</p>
                    <p class="text-sm text-gray-500 mt-1">{{ $affiliateParent->email }}</p>

                    <div class="mt-4 pt-4 border-t border-gray-200">
                        <x-button href="{{ route('vendor.marketplace.index') }}" variant="outline" size="sm">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>
                            </svg>
                            Browse Their Products
                        </x-button>
                    </div>
                </div>
            </div>
        </div>
        @endif

        <!-- Share Options -->
        <div class="bg-white rounded-xl border border-gray-200">
            <div class="px-6 py-6">
                <h3 class="text-base font-semibold text-gray-900 mb-4">Share Your Code</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <!-- WhatsApp Share -->
                    <button
                        onclick="shareViaWhatsApp()"
                        class="flex items-center justify-center space-x-3 bg-green-600 text-white px-6 py-3 rounded-lg hover:bg-green-700 transition-colors"
                    >
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/>
                        </svg>
                        <span class="font-medium">WhatsApp</span>
                    </button>

                    <!-- SMS Share -->
                    <button
                        onclick="shareViaSMS()"
                        class="flex items-center justify-center space-x-3 bg-brand-violet text-white px-6 py-3 rounded-lg hover:bg-brand-violet-deep transition-colors"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/>
                        </svg>
                        <span class="font-medium">SMS</span>
                    </button>

                    <!-- Email Share -->
                    <button
                        onclick="shareViaEmail()"
                        class="flex items-center justify-center space-x-3 bg-gray-800 text-white px-6 py-3 rounded-lg hover:bg-gray-900 transition-colors"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                        </svg>
                        <span class="font-medium">Email</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Referrals List -->
        <div class="bg-white rounded-xl border border-gray-200">
            <div class="px-6 py-6">
                <div class="mb-6">
                    <h2 class="text-base font-semibold text-gray-900">Your Referrals</h2>
                    <p class="text-sm text-gray-500">Vendors who used your affiliate code</p>
                </div>

                <div class="overflow-hidden rounded-xl border border-gray-100">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-100">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Vendor Name</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Email</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Phone</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Joined</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-100">
                                @forelse ($referrals as $referral)
                                    <tr class="hover:bg-violet-50/40 transition-colors duration-150">
                                        <td class="px-6 py-4 text-sm font-semibold text-gray-900">{{ $referral->name }}</td>
                                        <td class="px-6 py-4 text-sm text-gray-600">{{ $referral->email }}</td>
                                        <td class="px-6 py-4 text-sm text-gray-600">{{ $referral->phone_number }}</td>
                                        <td class="px-6 py-4 text-sm">
                                            @if($referral->is_approved)
                                                <x-badge variant="completed">Active</x-badge>
                                            @else
                                                <x-badge variant="pending">Pending</x-badge>
                                            @endif
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500">{{ $referral->created_at->format('M d, Y') }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-6 py-8 text-center">
                                            <div class="flex flex-col items-center justify-center">
                                                <svg class="w-12 h-12 text-gray-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                                                </svg>
                                                <p class="text-sm font-medium text-gray-500">No referrals yet.</p>
                                                <p class="text-xs text-gray-400 mt-1">Share your affiliate code to start building your network!</p>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                @if ($referrals->hasPages())
                    <div class="mt-6">
                        {{ $referrals->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-vendor-layout>

<script>
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        alert('Affiliate code copied to clipboard!');
    }).catch(err => {
        console.error('Failed to copy:', err);
    });
}

function shareViaWhatsApp() {
    const code = '{{ $vendor->vendor_code }}';
    const message = encodeURIComponent(`Join XTRA4U as a vendor! Use my affiliate code: ${code}\n\nRegister here: {{ route('vendor.request.form') }}`);
    window.open(`https://wa.me/?text=${message}`, '_blank');
}

function shareViaSMS() {
    const code = '{{ $vendor->vendor_code }}';
    const message = encodeURIComponent(`Join XTRA4U as a vendor! Use my affiliate code: ${code}. Register at {{ route('vendor.request.form') }}`);
    window.location.href = `sms:?body=${message}`;
}

function shareViaEmail() {
    const code = '{{ $vendor->vendor_code }}';
    const subject = encodeURIComponent('Join XTRA4U as a Vendor');
    const body = encodeURIComponent(`Hi,\n\nI'd like to invite you to join XTRA4U as a vendor!\n\nUse my affiliate code during registration: ${code}\n\nRegister here: {{ route('vendor.request.form') }}\n\nBest regards,\n{{ $vendor->name }}`);
    window.location.href = `mailto:?subject=${subject}&body=${body}`;
}
</script>
@endsection
