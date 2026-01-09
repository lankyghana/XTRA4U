@extends('layouts.app')

@section('title', 'Check Order Status - XTRA4U')
@section('description', 'Track your order status in real-time. Enter your recipient phone number to see the status of your orders.')

@section('content')
<div class="min-h-screen bg-gradient-to-br from-blue-50 via-white to-green-50 py-12" x-data="orderStatusChecker()">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="text-center mb-10">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-gradient-to-r from-brand-deep-blue to-brand-green rounded-full mb-4">
                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                </svg>
            </div>
            <h1 class="text-3xl sm:text-4xl font-bold text-gray-900">Check Order Status</h1>
            <p class="mt-3 text-lg text-gray-600">Enter the recipient phone number used during purchase to track your order.</p>
        </div>

        <!-- Search Form -->
        <div class="bg-white rounded-2xl shadow-xl p-6 sm:p-8 mb-8">
            <form @submit.prevent="checkStatus" class="space-y-4">
                <div>
                    <label for="phone" class="block text-sm font-medium text-gray-700 mb-2">Recipient Phone Number</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                            </svg>
                        </div>
                        <input 
                            type="tel" 
                            id="phone" 
                            x-model="phone"
                            placeholder="e.g., 0244123456"
                            inputmode="tel"
                            autocomplete="tel"
                            class="block w-full pl-12 pr-4 py-4 text-lg border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-brand-deep-blue focus:border-brand-deep-blue transition-colors"
                            required
                        >
                    </div>
                </div>
                
                <button 
                    type="submit" 
                    :disabled="loading"
                    class="w-full flex items-center justify-center px-6 py-4 bg-gradient-to-r from-brand-deep-blue to-brand-green text-white font-semibold text-lg rounded-xl hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-brand-deep-blue transition-all disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    <template x-if="loading">
                        <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </template>
                    <span x-text="loading ? 'Checking...' : 'Check Status'"></span>
                </button>
            </form>
        </div>

        <!-- Error Message -->
        <template x-if="error">
            <div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-8">
                <div class="flex items-center">
                    <svg class="h-5 w-5 text-red-500 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <p class="text-red-700" x-text="error"></p>
                </div>
            </div>
        </template>

        <!-- No Orders Found -->
        <template x-if="searched && orders.length === 0 && !error">
            <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6 text-center">
                <svg class="mx-auto h-12 w-12 text-yellow-500 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <h3 class="text-lg font-semibold text-yellow-800">No Orders Found</h3>
                <p class="mt-1 text-yellow-700">We couldn't find any orders for this phone number. Please check and try again.</p>
            </div>
        </template>

        <!-- Orders List -->
        <template x-if="orders.length > 0">
            <div class="space-y-4">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xl font-semibold text-gray-900">Your Orders</h2>
                    <span class="text-sm text-gray-500" x-text="`${orders.length} order(s) found`"></span>
                </div>
                
                <template x-for="order in orders" :key="order.id">
                    <div class="bg-white rounded-xl shadow-md hover:shadow-lg transition-shadow overflow-hidden">
                        <!-- Order Header -->
                        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                            <div>
                                <span class="text-sm text-gray-500">Order Reference</span>
                                <p class="font-mono font-semibold text-gray-900" x-text="order.reference"></p>
                            </div>
                            <div class="flex items-center" :class="order.status_color.bg + ' ' + order.status_color.text + ' px-3 py-1.5 rounded-full'">
                                <span class="w-2 h-2 rounded-full mr-2" :class="order.status_color.dot"></span>
                                <span class="text-sm font-semibold" x-text="order.status_label"></span>
                            </div>
                        </div>
                        
                        <!-- Order Details -->
                        <div class="px-6 py-4">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <span class="text-xs text-gray-500 uppercase tracking-wide">Service</span>
                                    <p class="font-medium text-gray-900 truncate" x-text="order.service"></p>
                                </div>
                                <div>
                                    <span class="text-xs text-gray-500 uppercase tracking-wide">Amount</span>
                                    <p class="font-semibold text-gray-900">GH₵ <span x-text="order.amount"></span></p>
                                </div>
                                <div>
                                    <span class="text-xs text-gray-500 uppercase tracking-wide">Vendor</span>
                                    <p class="font-medium text-gray-900" x-text="order.vendor_name"></p>
                                </div>
                                <div>
                                    <span class="text-xs text-gray-500 uppercase tracking-wide">Date</span>
                                    <p class="font-medium text-gray-900" x-text="order.date"></p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Order Footer -->
                        <div class="px-6 py-3 bg-gray-50 flex items-center justify-between text-sm">
                            <span class="text-gray-500">
                                <svg class="inline-block w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                <span x-text="order.time"></span>
                            </span>
                            <span class="text-gray-500">
                                Last updated: <span x-text="order.updated_at" class="font-medium"></span>
                            </span>
                        </div>
                    </div>
                </template>
            </div>
        </template>

        <!-- Status Legend -->
        <div class="mt-10 bg-white rounded-xl shadow p-6">
            <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide mb-4">Status Guide</h3>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div class="flex items-center">
                    <span class="w-3 h-3 rounded-full bg-yellow-500 mr-2"></span>
                    <span class="text-sm text-gray-600">Pending</span>
                </div>
                <div class="flex items-center">
                    <span class="w-3 h-3 rounded-full bg-blue-500 mr-2"></span>
                    <span class="text-sm text-gray-600">Processing</span>
                </div>
                <div class="flex items-center">
                    <span class="w-3 h-3 rounded-full bg-green-500 mr-2"></span>
                    <span class="text-sm text-gray-600">Completed</span>
                </div>
                <div class="flex items-center">
                    <span class="w-3 h-3 rounded-full bg-red-500 mr-2"></span>
                    <span class="text-sm text-gray-600">Cancelled</span>
                </div>
            </div>
        </div>

        <!-- Back to Home -->
        <div class="mt-8 text-center">
            <a href="{{ route('storefront.index') }}" class="inline-flex items-center text-brand-deep-blue hover:text-brand-bright-blue font-medium transition-colors">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Back to Home
            </a>
        </div>
    </div>
</div>

@push('scripts')
<script>
function orderStatusChecker() {
    return {
        phone: '',
        orders: [],
        loading: false,
        error: null,
        searched: false,
        pollingInterval: null,

        async checkStatus() {
            if (!this.phone || this.phone.length < 10) {
                this.error = 'Please enter a valid phone number';
                return;
            }

            this.loading = true;
            this.error = null;
            this.orders = [];

            try {
                const response = await fetch('{{ route("order.status.check") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ phone: this.phone })
                });

                const data = await response.json();

                if (data.success) {
                    this.orders = data.orders;
                    this.startPolling();
                } else {
                    this.error = data.message || 'No orders found for this phone number.';
                }
            } catch (err) {
                console.error(err);
                this.error = 'An error occurred. Please try again.';
            } finally {
                this.loading = false;
                this.searched = true;
            }
        },

        startPolling() {
            // Clear existing interval
            if (this.pollingInterval) {
                clearInterval(this.pollingInterval);
            }

            // Poll every 30 seconds for status updates
            this.pollingInterval = setInterval(async () => {
                if (this.orders.length === 0) {
                    clearInterval(this.pollingInterval);
                    return;
                }

                try {
                    const orderIds = this.orders.map(o => o.id);
                    const response = await fetch('{{ route("order.status.poll") }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({ order_ids: orderIds })
                    });

                    const data = await response.json();

                    // Update order statuses
                    data.orders.forEach(updated => {
                        const order = this.orders.find(o => o.id === updated.id);
                        if (order && order.status !== updated.status) {
                            order.status = updated.status;
                            order.status_label = updated.status_label;
                            order.status_color = updated.status_color;
                            order.updated_at = updated.updated_at;
                        }
                    });
                } catch (err) {
                    console.log('Polling error:', err);
                }
            }, 30000); // 30 seconds
        },

        // Clean up polling when leaving page
        destroy() {
            if (this.pollingInterval) {
                clearInterval(this.pollingInterval);
            }
        }
    };
}
</script>
@endpush
@endsection
