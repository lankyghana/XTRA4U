@extends('layouts.app')

@section('title', 'Check Result Checker Status - XTRA4U')
@section('description', 'Track your result checker order status in real-time. Enter your phone number or order reference to check your results.')

@section('content')
<div class="min-h-screen bg-gradient-to-br from-blue-50 via-white to-green-50 py-12" x-data="resultCheckerStatusChecker()">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="text-center mb-10">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-gradient-to-r from-brand-deep-blue to-brand-green rounded-full mb-4">
                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <h1 class="text-3xl sm:text-4xl font-bold text-gray-900">Check Result Status</h1>
            <p class="mt-3 text-lg text-gray-600">Enter your phone number or order reference to track your result checker order.</p>
        </div>

        <!-- Search Form -->
        <div class="bg-white rounded-2xl shadow-xl p-6 sm:p-8 mb-8">
            <form @submit.prevent="checkStatus" class="space-y-4">
                <div>
                    <label for="query" class="block text-sm font-medium text-gray-700 mb-2">Phone Number or Order Reference</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                        </div>
                        <input 
                            type="text" 
                            id="query" 
                            x-model="query"
                            placeholder="e.g., 0244123456 or order reference"
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

        <!-- Order Details -->
        <template x-if="order && !error">
            <div class="bg-white rounded-xl shadow-md overflow-hidden">
                <!-- Order Header -->
                <div class="px-6 py-4 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <span class="text-sm text-gray-500">Order ID</span>
                        <p class="font-mono font-semibold text-gray-900" x-text="order.id"></p>
                    </div>
                    <div class="flex items-center" :class="getStatusColor(order.status).bg + ' ' + getStatusColor(order.status).text + ' px-3 py-1.5 rounded-full'">
                        <span class="w-2 h-2 rounded-full mr-2" :class="getStatusColor(order.status).dot"></span>
                        <span class="text-sm font-semibold" x-text="getStatusLabel(order.status)"></span>
                    </div>
                </div>
                
                <!-- Order Details -->
                <div class="px-6 py-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                        <div>
                            <span class="text-xs text-gray-500 uppercase tracking-wide">Service</span>
                            <p class="font-medium text-gray-900 mt-1" x-text="order.service"></p>
                        </div>
                        <div>
                            <span class="text-xs text-gray-500 uppercase tracking-wide">Customer Name</span>
                            <p class="font-medium text-gray-900 mt-1" x-text="order.customer_name || 'N/A'"></p>
                        </div>
                        <div>
                            <span class="text-xs text-gray-500 uppercase tracking-wide">Reference</span>
                            <p class="font-mono font-semibold text-gray-900 mt-1 break-all" x-text="order.reference"></p>
                        </div>
                        <div>
                            <span class="text-xs text-gray-500 uppercase tracking-wide">Order Date</span>
                            <p class="font-medium text-gray-900 mt-1" x-text="formatDate(order.created_at)"></p>
                        </div>
                    </div>
                </div>

                <!-- Status Message -->
                <template x-if="order.message">
                    <div class="px-6 py-4 bg-blue-50 border-t border-blue-100">
                        <p class="text-blue-900" x-text="order.message"></p>
                    </div>
                </template>

                <!-- Pins (if delivered) -->
                <template x-if="order.pins_delivered && order.status === 'completed'">
                    <div class="px-6 py-4 bg-green-50 border-t border-green-100">
                        <div class="flex items-start">
                            <svg class="h-5 w-5 text-green-500 mr-3 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <div>
                                <p class="font-semibold text-green-900">Results Delivered!</p>
                                <p class="text-green-700 text-sm mt-1">Your results have been sent to your phone number. Check your SMS for the details.</p>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </template>

        <!-- Status Legend -->
        <div class="mt-10 bg-white rounded-xl shadow p-6">
            <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide mb-4">Status Guide</h3>
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                <div class="flex items-center">
                    <span class="w-3 h-3 rounded-full bg-yellow-500 mr-2"></span>
                    <span class="text-sm text-gray-600">Pending Payment</span>
                </div>
                <div class="flex items-center">
                    <span class="w-3 h-3 rounded-full bg-orange-500 mr-2"></span>
                    <span class="text-sm text-gray-600">Pending Stock</span>
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
                    <span class="text-sm text-gray-600">Failed</span>
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
function resultCheckerStatusChecker() {
    return {
        query: '',
        order: null,
        loading: false,
        error: null,

        async checkStatus() {
            if (!this.query || this.query.length < 5) {
                this.error = 'Please enter a valid phone number or order reference';
                return;
            }

            this.loading = true;
            this.error = null;
            this.order = null;

            try {
                const response = await fetch('{{ route("result-checkers.status.check") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ query: this.query })
                });

                const data = await response.json();

                if (data.success && data.data) {
                    this.order = data.data;
                } else {
                    this.error = data.message || 'Order not found. Please check your reference and try again.';
                }
            } catch (err) {
                console.error(err);
                this.error = 'An error occurred. Please try again.';
            } finally {
                this.loading = false;
            }
        },

        getStatusColor(status) {
            const colors = {
                'pending_payment': { bg: 'bg-yellow-100', text: 'text-yellow-800', dot: 'bg-yellow-500' },
                'pending_stock': { bg: 'bg-orange-100', text: 'text-orange-800', dot: 'bg-orange-500' },
                'processing': { bg: 'bg-blue-100', text: 'text-blue-800', dot: 'bg-blue-500' },
                'completed': { bg: 'bg-green-100', text: 'text-green-800', dot: 'bg-green-500' },
                'failed': { bg: 'bg-red-100', text: 'text-red-800', dot: 'bg-red-500' },
            };
            return colors[status] || { bg: 'bg-gray-100', text: 'text-gray-800', dot: 'bg-gray-500' };
        },

        getStatusLabel(status) {
            const labels = {
                'pending_payment': 'Awaiting Payment',
                'pending_stock': 'Pending Stock',
                'processing': 'Processing',
                'completed': 'Completed',
                'failed': 'Failed',
            };
            return labels[status] || 'Unknown';
        },

        formatDate(dateString) {
            try {
                const date = new Date(dateString);
                return date.toLocaleDateString('en-US', { 
                    year: 'numeric', 
                    month: 'long', 
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            } catch {
                return dateString;
            }
        }
    };
}
</script>
@endpush
@endsection
