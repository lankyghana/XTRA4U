{{--
    result_checkers/status.blade.php

    Public result-checker status lookup (route: GET /results-checker/status).
    Visual redesign only — the `resultCheckerStatusChecker()` Alpine
    component at the bottom of this file (fetch to
    result-checkers.status.check, status/label/date helpers) is unchanged;
    only the markup/classes around it were rewritten to the storefront's
    `x4` design system. Mirrors the structure already used for
    /order-status, adapted for the result-checker's own status vocabulary
    (pending_payment, pending_stock, processing, completed, failed).
--}}
@extends('layouts.app')

@section('title', 'Check Result Checker Status - XTRA4U')
@section('description', 'Track your result checker order status in real-time. Enter your phone number or order reference to check your results.')

{{-- Scope the storefront design system to this page only. --}}
@section('body-class', 'x4')

@php
    // Same flagship-store fallback the marketplace homepage uses for its
    // "Buy Now" entry points — this page isn't tied to any one vendor.
    $mainStoreVendor = \App\Support\MainStore::vendor();
    $shopUrl = $mainStoreVendor
        ? route('storefront.vendor', ['vendor' => $mainStoreVendor->vendor_code])
        : route('checkout.show');
@endphp

@section('site-header')
    <x-storefront.header :shop-url="$shopUrl" />
@endsection

@section('site-footer')
    <x-storefront.footer :shop-url="$shopUrl" />
@endsection

@section('content')
<div class="x4-page" style="padding-top: 64px;">
    <section class="relative overflow-hidden" style="background: #fff; min-height: calc(100vh - 64px);">
        <div class="x4-hero-wash absolute inset-0" aria-hidden="true" style="pointer-events: none;"></div>

        <div class="relative max-w-3xl mx-auto px-5" style="padding-top: 56px; padding-bottom: 72px;" x-data="resultCheckerStatusChecker()">

            {{-- ============================================================
                 Header
                 ============================================================ --}}
            <x-storefront.reveal from="up" class="text-center mb-9">
                <div
                    class="mx-auto mb-5 flex items-center justify-center"
                    style="width: 64px; height: 64px; border-radius: var(--x4-r-lg); background-color: var(--x4-violet);"
                >
                    <x-storefront.icon name="check" class="w-7 h-7" style="color: #fff;" />
                </div>

                <h1 class="x4-display-xl mb-3" style="color: var(--x4-ink-strong);">Check Result Status</h1>
                <p class="x4-body-lg" style="color: var(--x4-ink-body);">
                    Enter your phone number or order reference to track your result checker order.
                </p>
            </x-storefront.reveal>

            {{-- ============================================================
                 Search form
                 ============================================================ --}}
            <x-storefront.reveal :delay="60">
            <div class="x4-panel mb-6" style="padding: 24px;">
                <form @submit.prevent="checkStatus">
                    <label for="query" class="x4-caption block mb-2" style="color: var(--x4-ink-sec); font-weight: 500;">Phone Number or Order Reference</label>
                    <div class="relative mb-4">
                        <x-storefront.icon
                            name="search"
                            class="w-4 h-4 absolute"
                            style="left: 14px; top: 50%; transform: translateY(-50%); color: var(--x4-ink-mute); pointer-events: none;"
                        />
                        <input
                            type="text"
                            id="query"
                            x-model="query"
                            placeholder="e.g., 0244123456 or order reference"
                            required
                            class="x4-input"
                            style="padding-left: 38px; padding-top: 13px; padding-bottom: 13px; font-size: 16px;"
                        >
                    </div>

                    <button
                        type="submit"
                        :disabled="loading"
                        class="x4-btn x4-btn-primary w-full"
                        style="padding: 13px 22px;"
                    >
                        <template x-if="loading">
                            <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </template>
                        <span x-text="loading ? 'Checking…' : 'Check Status'"></span>
                    </button>
                </form>
            </div>
            </x-storefront.reveal>

            {{-- ============================================================
                 Error
                 ============================================================ --}}
            <template x-if="error">
                <div class="mb-6" style="background-color: #fef2f2; border: 1px solid #fecaca; border-radius: var(--x4-r-lg); padding: 16px 18px;">
                    <div class="flex items-center gap-3">
                        <x-storefront.icon name="close" class="w-4 h-4 flex-shrink-0" style="color: #dc2626;" />
                        <p class="x4-body-md" style="color: #991b1b;" x-text="error"></p>
                    </div>
                </div>
            </template>

            {{-- ============================================================
                 Order details
                 ============================================================ --}}
            <template x-if="order && !error">
                <div class="x4-panel mb-6" style="overflow: hidden;">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3" style="padding: 18px 20px; border-bottom: 1px solid var(--x4-hairline); background-color: var(--x4-canvas-soft);">
                        <div>
                            <span class="x4-micro-cap" style="color: var(--x4-ink-mute);">Order ID</span>
                            <p class="x4-tnum" style="font-size: 14px; font-weight: 500; color: var(--x4-ink);" x-text="order.id"></p>
                        </div>
                        <div class="inline-flex items-center flex-shrink-0" :class="getStatusColor(order.status).bg + ' ' + getStatusColor(order.status).text" style="padding: 5px 12px; border-radius: var(--x4-r-pill);">
                            <span class="w-1.5 h-1.5 rounded-full mr-2" :class="getStatusColor(order.status).dot"></span>
                            <span class="x4-caption" style="font-weight: 500;" x-text="getStatusLabel(order.status)"></span>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5" style="padding: 20px;">
                        <div>
                            <span class="x4-micro-cap" style="color: var(--x4-ink-mute);">Service</span>
                            <p class="x4-body-md mt-1" style="font-weight: 500; color: var(--x4-ink);" x-text="order.service"></p>
                        </div>
                        <div>
                            <span class="x4-micro-cap" style="color: var(--x4-ink-mute);">Customer Name</span>
                            <p class="x4-body-md mt-1" style="font-weight: 500; color: var(--x4-ink);" x-text="order.customer_name || 'N/A'"></p>
                        </div>
                        <div>
                            <span class="x4-micro-cap" style="color: var(--x4-ink-mute);">Reference</span>
                            <p class="x4-caption x4-tnum mt-1" style="font-weight: 500; color: var(--x4-ink); word-break: break-all;" x-text="order.reference"></p>
                        </div>
                        <div>
                            <span class="x4-micro-cap" style="color: var(--x4-ink-mute);">Order Date</span>
                            <p class="x4-body-md mt-1" style="font-weight: 500; color: var(--x4-ink);" x-text="formatDate(order.created_at)"></p>
                        </div>
                    </div>

                    <template x-if="order.message">
                        <div style="padding: 14px 20px; background-color: var(--x4-violet-soft); border-top: 1px solid var(--x4-violet-soft-deep);">
                            <p class="x4-body-md" style="color: var(--x4-primary-deep);" x-text="order.message"></p>
                        </div>
                    </template>

                    <template x-if="order.pins_delivered && order.status === 'completed'">
                        <div style="padding: 16px 20px; background-color: #f0fdf4; border-top: 1px solid #bbf7d0;">
                            <div class="flex items-start gap-3">
                                <x-storefront.icon name="check" class="w-4 h-4 flex-shrink-0 mt-0.5" style="color: #16a34a;" />
                                <div>
                                    <p class="x4-body-md" style="font-weight: 500; color: #166534;">Results Delivered!</p>
                                    <p class="x4-caption mt-0.5" style="color: #15803d;">Your results have been sent to your phone number. Check your SMS for the details.</p>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </template>

            {{-- ============================================================
                 Status legend
                 ============================================================ --}}
            <x-storefront.reveal :delay="100">
            <div class="x4-panel mb-8" style="padding: 20px 24px;">
                <p class="x4-micro-cap mb-4" style="color: var(--x4-ink-mute);">Status Guide</p>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                    @foreach ([
                        ['label' => 'Pending Payment', 'dot' => '#eab308'],
                        ['label' => 'Pending Stock', 'dot' => '#f97316'],
                        ['label' => 'Processing', 'dot' => '#3b82f6'],
                        ['label' => 'Completed', 'dot' => '#22c55e'],
                        ['label' => 'Failed', 'dot' => '#ef4444'],
                    ] as $status)
                        <div class="flex items-center gap-2">
                            <span aria-hidden="true" style="width: 8px; height: 8px; border-radius: 9999px; background-color: {{ $status['dot'] }}; flex-shrink: 0;"></span>
                            <span class="x4-caption" style="color: var(--x4-ink-sec);">{{ $status['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
            </x-storefront.reveal>

            {{-- ============================================================
                 Back to home
                 ============================================================ --}}
            <div class="text-center">
                <a href="{{ route('storefront.index') }}" class="x4-caption x4-link x4-link-accent inline-flex items-center gap-2" style="color: var(--x4-ink-mute); font-weight: 500;">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                    </svg>
                    Back to Home
                </a>
            </div>
        </div>
    </section>
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
