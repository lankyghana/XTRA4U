@extends('layouts.vendor')

@section('title', 'AFA Registration Details - XTRA4U')

@section('content')
<x-vendor-layout :vendor="$vendor" title="AFA Registration" subtitle="Registration #{{ $registration->reference }}" active="afa">
    <div class="space-y-6">
        @if(session('success'))
            <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl">
                <div class="flex items-center">
                    <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                    {{ session('success') }}
                </div>
            </div>
        @endif

        <!-- Back Button -->
        <a href="{{ route('vendor.afa.index') }}" class="inline-flex items-center text-gray-600 hover:text-brand-violet transition-colors">
            <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            Back to Registrations
        </a>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Main Details Card -->
            <div class="lg:col-span-2 bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">{{ $registration->full_name }}</h2>
                            <div class="flex items-center gap-2 mt-1">
                                <p class="text-sm text-gray-500 font-mono">{{ $registration->reference }}</p>
                                @if($registration->reseller_vendor_id)
                                    @if($registration->reseller_vendor_id == $vendor->id)
                                        {{-- Current vendor is the reseller --}}
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-brand-violet-soft text-brand-violet-deep">
                                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                                            </svg>
                                            Your Reseller Sale
                                        </span>
                                    @else
                                        {{-- Current vendor is the provider --}}
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-brand-violet-soft text-brand-violet-deep">
                                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/>
                                            </svg>
                                            Sold by Reseller
                                        </span>
                                    @endif
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700">
                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                        </svg>
                                        Direct Sale
                                    </span>
                                @endif
                            </div>
                        </div>
                        <span id="afa-header-status-badge" class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-brand-violet-soft text-brand-violet-deep">
                            {{ $registration->status_label }}
                        </span>
                    </div>
                </div>

                <div class="p-6">
                    <!-- Customer Information -->
                    <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                        <svg class="w-5 h-5 mr-2 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                        Customer Information
                    </h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                        <div class="bg-gray-50 rounded-lg p-4">
                            <label class="text-xs font-medium text-gray-500 uppercase">Full Name</label>
                            <p class="text-gray-900 font-medium">{{ $registration->full_name }}</p>
                        </div>
                        <div class="bg-gray-50 rounded-lg p-4">
                            <label class="text-xs font-medium text-gray-500 uppercase">Phone Number</label>
                            <p class="text-gray-900 font-medium">{{ $registration->phone_number }}</p>
                        </div>
                        <div class="bg-gray-50 rounded-lg p-4">
                            <label class="text-xs font-medium text-gray-500 uppercase">Date of Birth</label>
                            <p class="text-gray-900 font-medium">{{ $registration->date_of_birth?->format('F d, Y') }}</p>
                        </div>
                        <div class="bg-gray-50 rounded-lg p-4">
                            <label class="text-xs font-medium text-gray-500 uppercase">Occupation</label>
                            <p class="text-gray-900 font-medium">{{ $registration->occupation }}</p>
                        </div>
                    </div>

                    <!-- ID Information -->
                    <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                        <svg class="w-5 h-5 mr-2 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2"/>
                        </svg>
                        Identification
                    </h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                        <div class="bg-gray-50 rounded-lg p-4">
                            <label class="text-xs font-medium text-gray-500 uppercase">ID Type</label>
                            <p class="text-gray-900 font-medium">{{ $registration->id_type_label }}</p>
                        </div>
                        <div class="bg-gray-50 rounded-lg p-4">
                            <label class="text-xs font-medium text-gray-500 uppercase">ID Number</label>
                            <p class="text-gray-900 font-medium font-mono">{{ $registration->id_number }}</p>
                        </div>
                    </div>

                    <!-- Location Information -->
                    <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                        <svg class="w-5 h-5 mr-2 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        Location
                    </h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="bg-gray-50 rounded-lg p-4">
                            <label class="text-xs font-medium text-gray-500 uppercase">Location/Address</label>
                            <p class="text-gray-900 font-medium">{{ $registration->location }}</p>
                        </div>
                        <div class="bg-gray-50 rounded-lg p-4">
                            <label class="text-xs font-medium text-gray-500 uppercase">Region</label>
                            <p class="text-gray-900 font-medium">{{ $registration->region }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="space-y-6">
                <!-- Status & Actions Card -->
                <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Status & Actions</h3>
                        
                        <div class="mb-6">
                            <span id="afa-sidebar-status-badge" class="inline-flex items-center px-4 py-2 rounded-full text-sm font-medium {{ $registration->status_color['bg'] }} {{ $registration->status_color['text'] }}">
                                {{ $registration->status_label }}
                            </span>
                        </div>

                        @php
                            // Only the main provider (vendor_id) may ever update AFA status.
                            // Resellers have view-only access regardless of order type.
                            $canManage = ((int) $registration->vendor_id === (int) $vendor->id);
                            $isResellerViewer = $registration->is_reseller_order
                                && ((int) $registration->reseller_vendor_id === (int) $vendor->id);
                        @endphp

                        @if(!in_array($registration->status, ['completed', 'cancelled', 'rejected']))
                            @if($canManage)
                                <form method="POST" action="{{ route('vendor.afa.update-status', $registration) }}" class="space-y-3">
                                    @csrf
                                    @method('PATCH')
                                    
                                    <select name="status" class="w-full border-gray-300 rounded-lg focus:ring-brand-violet-deep focus:border-brand-violet-deep">
                                        <option value="">Change Status</option>
                                        @if($registration->status === 'pending')
                                            <option value="processing">Mark as Processing</option>
                                            <option value="rejected">Reject Registration</option>
                                        @elseif($registration->status === 'processing')
                                            <option value="approved">Approve Registration</option>
                                            <option value="rejected">Reject Registration</option>
                                        @elseif($registration->status === 'approved')
                                            <option value="completed">Mark as Completed</option>
                                        @endif
                                    </select>
                                    
                                    <button type="submit" class="w-full px-4 py-2 bg-brand-violet text-white font-medium rounded-lg hover:bg-brand-violet-deep transition-colors">
                                        Update Status
                                    </button>
                                </form>
                            @elseif($isResellerViewer)
                                {{-- Reseller view: live-polling read-only status panel --}}
                                <div class="rounded-lg border border-blue-200 bg-blue-50 p-4" id="reseller-status-panel">
                                    <div class="flex items-start gap-3">
                                        <div class="flex-shrink-0 mt-0.5">
                                            <svg class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                            </svg>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-semibold text-blue-900">Live Order Status</p>
                                            <p class="text-xs text-blue-600 mt-0.5">Status is managed by the main provider. This panel updates automatically.</p>
                                            <div class="mt-3 flex items-center gap-2">
                                                <span id="live-status-badge"
                                                      class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold {{ $registration->status_color['bg'] }} {{ $registration->status_color['text'] }}">
                                                    {{ $registration->status_label }}
                                                </span>
                                                <span id="live-status-pulse" class="inline-flex h-2 w-2 rounded-full bg-blue-400 animate-pulse" title="Auto-refreshing"></span>
                                                <span id="live-status-timestamp" class="text-xs text-blue-400">Just loaded</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <script>
                                (function () {
                                    var statusUrl    = '{{ route('vendor.afa.get-status', $registration) }}';
                                    var liveBadge    = document.getElementById('live-status-badge');
                                    var headerBadge  = document.getElementById('afa-header-status-badge');
                                    var sidebarBadge = document.getElementById('afa-sidebar-status-badge');
                                    var panel        = document.getElementById('reseller-status-panel');
                                    var ts           = document.getElementById('live-status-timestamp');
                                    var pulse        = document.getElementById('live-status-pulse');
                                    var lastStatus   = '{{ $registration->status }}';

                                    // Colour maps for the coloured sidebar badge
                                    var colorMap = {
                                        'pending':    { bg: 'bg-yellow-100', text: 'text-yellow-800' },
                                        'processing': { bg: 'bg-blue-100',   text: 'text-blue-800'   },
                                        'approved':   { bg: 'bg-green-100',  text: 'text-green-800'  },
                                        'completed':  { bg: 'bg-green-100',  text: 'text-green-800'  },
                                        'rejected':   { bg: 'bg-red-100',    text: 'text-red-800'    },
                                        'cancelled':  { bg: 'bg-gray-100',   text: 'text-gray-800'   },
                                    };

                                    function applyAllBadges(status, label) {
                                        var colors = colorMap[status] || { bg: 'bg-gray-100', text: 'text-gray-800' };

                                        // Live panel badge
                                        liveBadge.className = 'inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold ' + colors.bg + ' ' + colors.text;
                                        liveBadge.textContent = label;

                                        // Header badge (white-tinted, always visible in card header)
                                        if (headerBadge) {
                                            headerBadge.textContent = label;
                                        }

                                        // Sidebar static badge above the panel
                                        if (sidebarBadge) {
                                            sidebarBadge.className = 'inline-flex items-center px-4 py-2 rounded-full text-sm font-medium ' + colors.bg + ' ' + colors.text;
                                            sidebarBadge.textContent = label;
                                        }
                                    }

                                    function onTerminal(status, label) {
                                        clearInterval(timer);
                                        pulse.classList.add('hidden');

                                        if (status === 'rejected') {
                                            // Replace the live panel with a clear rejection notice
                                            panel.innerHTML =
                                                '<div class="flex items-start gap-3">' +
                                                    '<div class="flex-shrink-0 mt-0.5">' +
                                                        '<svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">' +
                                                            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/>' +
                                                        '</svg>' +
                                                    '</div>' +
                                                    '<div>' +
                                                        '<p class="text-sm font-semibold text-red-900">Registration Rejected</p>' +
                                                        '<p class="text-xs text-red-600 mt-0.5">This order has been rejected by the main provider.</p>' +
                                                    '</div>' +
                                                '</div>';
                                            panel.className = 'rounded-lg border border-red-200 bg-red-50 p-4';
                                        } else {
                                            ts.textContent = 'Final status: ' + label;
                                        }
                                    }

                                    function poll() {
                                        fetch(statusUrl, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                                            .then(function (r) { return r.json(); })
                                            .then(function (data) {
                                                if (data && data.status) {
                                                    applyAllBadges(data.status, data.status_label);

                                                    if (data.status !== lastStatus) {
                                                        lastStatus = data.status;
                                                        // Flash pulse green on change
                                                        pulse.classList.remove('bg-blue-400');
                                                        pulse.classList.add('bg-green-400');
                                                        setTimeout(function () {
                                                            pulse.classList.remove('bg-green-400');
                                                            pulse.classList.add('bg-blue-400');
                                                        }, 3000);
                                                    }

                                                    var now = new Date();
                                                    if (ts) ts.textContent = 'Updated ' + now.toLocaleTimeString();

                                                    // Stop polling and render terminal UI on final states
                                                    if (data.status === 'completed' || data.status === 'cancelled' || data.status === 'rejected') {
                                                        onTerminal(data.status, data.status_label);
                                                    }
                                                }
                                            })
                                            .catch(function () { /* silent — retry on next tick */ });
                                    }

                                    // Poll every 15 seconds
                                    var timer = setInterval(poll, 15000);
                                })();
                                </script>
                            @else
                                <div class="bg-amber-50 border border-amber-200 rounded-lg p-4">
                                    <div class="flex items-start">
                                        <svg class="w-5 h-5 text-amber-600 mr-2 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                                        </svg>
                                        <div>
                                            <p class="text-sm font-medium text-amber-900">Status Management Restricted</p>
                                            <p class="text-xs text-amber-700 mt-1">Only the main provider can manage this registration's fulfillment status.</p>
                                        </div>
                                    </div>
                                </div>
                            @endif
                        @else
                            <p class="text-sm text-gray-500">This registration is {{ $registration->status }}. No further actions available.</p>
                        @endif
                    </div>
                </div>

                <!-- Payment Card -->
                <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Payment Details</h3>
                        
                        <div class="space-y-3">
                            <div class="flex justify-between py-2 border-b border-gray-100">
                                <span class="text-gray-500">Amount Paid</span>
                                <span class="font-bold text-gray-900">GH₵ {{ number_format($registration->amount, 2) }}</span>
                            </div>
                            <div class="flex justify-between py-2 border-b border-gray-100">
                                <span class="text-gray-500">Your Earning</span>
                                <span class="font-bold text-green-600">GH₵ {{ number_format($registration->vendor_earning, 2) }}</span>
                            </div>
                            @if($registration->reseller_earning > 0)
                            <div class="flex justify-between py-2 border-b border-gray-100">
                                <span class="text-gray-500">Affiliate Earning</span>
                                <span class="font-medium text-brand-violet">GH₵ {{ number_format($registration->reseller_earning, 2) }}</span>
                            </div>
                            @endif
                            <div class="flex justify-between py-2">
                                <span class="text-gray-500">Payment Status</span>
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $registration->payment_status === 'paid' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700' }}">
                                    {{ ucfirst($registration->payment_status) }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Timeline Card -->
                <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Timeline</h3>
                        
                        <div class="space-y-4">
                            <div class="flex items-start">
                                <div class="w-3 h-3 rounded-full bg-green-500 mt-1 mr-3"></div>
                                <div>
                                    <p class="text-sm font-medium text-gray-900">Registered</p>
                                    <p class="text-xs text-gray-500">{{ $registration->created_at?->format('M d, Y h:i A') }}</p>
                                </div>
                            </div>
                            @if($registration->processed_at)
                            <div class="flex items-start">
                                <div class="w-3 h-3 rounded-full bg-blue-500 mt-1 mr-3"></div>
                                <div>
                                    <p class="text-sm font-medium text-gray-900">Processing Started</p>
                                    <p class="text-xs text-gray-500">{{ $registration->processed_at->format('M d, Y h:i A') }}</p>
                                </div>
                            </div>
                            @endif
                            @if($registration->approved_at)
                            <div class="flex items-start">
                                <div class="w-3 h-3 rounded-full bg-cyan-500 mt-1 mr-3"></div>
                                <div>
                                    <p class="text-sm font-medium text-gray-900">Approved</p>
                                    <p class="text-xs text-gray-500">{{ $registration->approved_at->format('M d, Y h:i A') }}</p>
                                </div>
                            </div>
                            @endif
                            @if($registration->completed_at)
                            <div class="flex items-start">
                                <div class="w-3 h-3 rounded-full bg-green-500 mt-1 mr-3"></div>
                                <div>
                                    <p class="text-sm font-medium text-gray-900">Completed</p>
                                    <p class="text-xs text-gray-500">{{ $registration->completed_at->format('M d, Y h:i A') }}</p>
                                </div>
                            </div>
                            @endif
                        </div>
                    </div>
                </div>

                <!-- WhatsApp Contact -->
                <div class="bg-green-50 rounded-xl border border-green-200 p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">Contact Customer</h3>
                    <p class="text-sm text-gray-600 mb-4">Send a message to the customer on WhatsApp.</p>
                    <a 
                        href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $registration->phone_number) }}?text=Hello {{ $registration->full_name }}, regarding your AFA registration ({{ $registration->reference }}), "
                        target="_blank"
                        class="inline-flex items-center px-4 py-2 bg-green-600 text-white font-medium rounded-lg hover:bg-green-700 transition-colors"
                    >
                        <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/>
                        </svg>
                        Message on WhatsApp
                    </a>
                </div>
            </div>
        </div>
    </div>
</x-vendor-layout>
@endsection
