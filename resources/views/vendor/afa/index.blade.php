@extends('layouts.vendor')

@section('title', 'AFA Registrations - XTRA4U')

@section('content')
<x-vendor-layout :vendor="$vendor" title="AFA Registrations" subtitle="Manage AFA registration orders" active="afa">
    <div class="space-y-6">
        @if(session('success'))
            <div class="bg-gradient-to-r from-green-50 to-emerald-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg shadow-md">
                <div class="flex items-center">
                    <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                    {{ session('success') }}
                </div>
            </div>
        @endif
        
        @if(session('error'))
            <div class="bg-gradient-to-r from-red-50 to-pink-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg shadow-md">
                <div class="flex items-center">
                    <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                    </svg>
                    {{ session('error') }}
                </div>
            </div>
        @endif

        <!-- Navigation Tabs -->
        <div class="flex flex-wrap gap-2 border-b border-gray-200 pb-2">
            <a href="{{ route('vendor.afa.index') }}" 
               class="px-4 py-2 text-sm font-medium {{ !request('status') ? 'text-brand-deep-blue bg-blue-50 rounded-lg' : 'text-gray-600 hover:text-brand-deep-blue' }}">
                All
            </a>
            <a href="{{ route('vendor.afa.index', ['status' => 'pending']) }}" 
               class="px-4 py-2 text-sm font-medium {{ request('status') === 'pending' ? 'text-brand-deep-blue bg-blue-50 rounded-lg' : 'text-gray-600 hover:text-brand-deep-blue' }}">
                Pending
            </a>
            <a href="{{ route('vendor.afa.index', ['status' => 'processing']) }}" 
               class="px-4 py-2 text-sm font-medium {{ request('status') === 'processing' ? 'text-brand-deep-blue bg-blue-50 rounded-lg' : 'text-gray-600 hover:text-brand-deep-blue' }}">
                Processing
            </a>
            <a href="{{ route('vendor.afa.index', ['status' => 'approved']) }}" 
               class="px-4 py-2 text-sm font-medium {{ request('status') === 'approved' ? 'text-brand-deep-blue bg-blue-50 rounded-lg' : 'text-gray-600 hover:text-brand-deep-blue' }}">
                Approved
            </a>
            <a href="{{ route('vendor.afa.index', ['status' => 'completed']) }}" 
               class="px-4 py-2 text-sm font-medium {{ request('status') === 'completed' ? 'text-brand-deep-blue bg-blue-50 rounded-lg' : 'text-gray-600 hover:text-brand-deep-blue' }}">
                Completed
            </a>
            <a href="{{ route('vendor.afa.settings') }}" 
               class="ml-auto px-4 py-2 text-sm font-medium text-gray-600 hover:text-brand-deep-blue flex items-center">
                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                Settings
            </a>
        </div>
        
        <!-- Registrations Table -->
        <div class="bg-gradient-to-br from-white to-gray-50 rounded-xl shadow-lg border border-gray-200">
            <div class="px-6 py-6">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h1 class="text-xl font-bold text-gray-900">AFA Registrations</h1>
                        <p class="text-sm text-gray-600">Customer registrations submitted through your storefront.</p>
                    </div>
                    <p class="text-sm text-gray-500">
                        Showing <span class="font-semibold text-brand-deep-blue">{{ $registrations->count() }}</span> of 
                        <span class="font-semibold text-brand-deep-blue">{{ $registrations->total() }}</span> registrations
                    </p>
                </div>

                <div class="overflow-hidden rounded-lg border border-gray-200">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gradient-to-r from-gray-50 to-slate-100">
                                <tr>
                                    <th class="px-4 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Reference</th>
                                    <th class="px-4 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Customer</th>
                                    <th class="px-4 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">ID Info</th>
                                    <th class="px-4 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Region</th>
                                    <th class="px-4 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Amount</th>
                                    <th class="px-4 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Status</th>
                                    <th class="px-4 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Date</th>
                                    <th class="px-4 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">Action</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @forelse ($registrations as $reg)
                                    <tr class="hover:bg-gradient-to-r hover:from-green-50 hover:to-blue-50 transition-all duration-200">
                                        <td class="px-4 py-4 text-sm">
                                            <span class="font-mono font-semibold text-gray-900">{{ $reg->reference }}</span>
                                            @if($reg->reseller_vendor_id)
                                                <span class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-700">
                                                    Affiliate
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-4 text-sm">
                                            <div class="font-medium text-gray-900">{{ $reg->full_name }}</div>
                                            <div class="text-gray-500">{{ $reg->phone_number }}</div>
                                        </td>
                                        <td class="px-4 py-4 text-sm">
                                            <div class="font-medium text-gray-900">{{ $reg->id_type_label }}</div>
                                            <div class="text-gray-500 font-mono">{{ $reg->id_number }}</div>
                                        </td>
                                        <td class="px-4 py-4 text-sm text-gray-600">
                                            <div>{{ $reg->location }}</div>
                                            <div class="text-gray-400">{{ $reg->region }}</div>
                                        </td>
                                        <td class="px-4 py-4 text-sm">
                                            <div class="font-semibold text-gray-900">GH₵ {{ number_format($reg->amount, 2) }}</div>
                                            <div class="text-xs text-green-600">Earn: GH₵ {{ number_format($reg->vendor_earning, 2) }}</div>
                                        </td>
                                        <td class="px-4 py-4 text-sm">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $reg->status_color['bg'] }} {{ $reg->status_color['text'] }}">
                                                {{ $reg->status_label }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-4 text-sm text-gray-500">{{ $reg->created_at?->format('M d, Y') }}</td>
                                        <td class="px-4 py-4 text-sm">
                                            <div class="flex items-center space-x-2">
                                                <a href="{{ route('vendor.afa.show', $reg) }}" 
                                                   class="text-blue-600 hover:text-blue-800 font-medium">
                                                    View
                                                </a>
                                                @if(!in_array($reg->status, ['completed', 'cancelled']))
                                                    <form method="POST" action="{{ route('vendor.afa.update-status', $reg) }}" class="inline-block">
                                                        @csrf
                                                        @method('PATCH')
                                                        <select name="status" 
                                                                onchange="this.form.submit()" 
                                                                class="text-xs border-gray-300 rounded-lg focus:ring-brand-bright-blue focus:border-brand-bright-blue px-2 py-1 font-medium shadow-sm">
                                                            <option value="">Change</option>
                                                            @if($reg->status === 'pending')
                                                                <option value="processing">Processing</option>
                                                                <option value="rejected">Reject</option>
                                                            @elseif($reg->status === 'processing')
                                                                <option value="approved">Approve</option>
                                                                <option value="rejected">Reject</option>
                                                            @elseif($reg->status === 'approved')
                                                                <option value="completed">Complete</option>
                                                            @endif
                                                        </select>
                                                    </form>
                                                @else
                                                    <span class="text-xs text-gray-400">—</span>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="px-6 py-8 text-center">
                                            <div class="flex flex-col items-center justify-center">
                                                <svg class="w-12 h-12 text-gray-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                                </svg>
                                                <p class="text-sm font-medium text-gray-500">No AFA registrations yet.</p>
                                                <p class="text-xs text-gray-400 mt-1">Registrations will appear here when customers apply through your storefront.</p>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                @if ($registrations->hasPages())
                    <div class="mt-6">
                        {{ $registrations->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-vendor-layout>
@endsection
