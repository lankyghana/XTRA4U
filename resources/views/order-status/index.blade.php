{{--
    order-status/index.blade.php

    Public order-status lookup (route: GET /order-status). Visual redesign
    only — the `orderStatusChecker()` Alpine component at the bottom of
    this file (routes, fetch calls, polling) is unchanged; only the
    markup/classes around it were rewritten to the storefront's `x4`
    design system. Status colours/labels are server-driven (from
    OrderStatusController) and arrive as literal Tailwind class strings in
    the JSON payload, so those bindings are also left exactly as they were.
--}}
@extends('layouts.app')

@section('title', 'Check Order Status - XTRA4U')
@section('description', 'Track your order status in real-time. Enter your recipient phone number to see the status of your orders.')

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

        <div class="relative max-w-3xl mx-auto px-5" style="padding-top: 56px; padding-bottom: 72px;" x-data="orderStatusChecker()">

            {{-- ============================================================
                 Header
                 ============================================================ --}}
            <x-storefront.reveal from="up" class="text-center mb-9">
                <div
                    class="mx-auto mb-5 flex items-center justify-center"
                    style="width: 64px; height: 64px; border-radius: var(--x4-r-lg); background-color: var(--x4-violet);"
                >
                    <x-storefront.icon name="clock" class="w-7 h-7" style="color: #fff;" />
                </div>

                <h1 class="x4-display-xl mb-3" style="color: var(--x4-ink-strong);">Check Order Status</h1>
                <p class="x4-body-lg" style="color: var(--x4-ink-body);">
                    Enter the recipient phone number used during purchase to track your order.
                </p>
            </x-storefront.reveal>

            {{-- ============================================================
                 Search form
                 ============================================================ --}}
            <x-storefront.reveal :delay="60">
            <div class="x4-panel mb-6" style="padding: 24px;">
                <form @submit.prevent="checkStatus">
                    <label for="phone" class="x4-caption block mb-2" style="color: var(--x4-ink-sec); font-weight: 500;">Recipient Phone Number</label>
                    <div class="relative mb-4">
                        <x-storefront.icon
                            name="phone"
                            class="w-4 h-4 absolute"
                            style="left: 14px; top: 50%; transform: translateY(-50%); color: var(--x4-ink-mute); pointer-events: none;"
                        />
                        <input
                            type="tel"
                            id="phone"
                            x-model="phone"
                            placeholder="e.g., 0244123456"
                            inputmode="tel"
                            autocomplete="tel"
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
                 No orders found
                 ============================================================ --}}
            <template x-if="searched && orders.length === 0 && !error">
                <div class="text-center mb-6" style="background-color: #fffbeb; border: 1px solid #fde68a; border-radius: var(--x4-r-lg); padding: 28px 20px;">
                    <div class="mx-auto mb-3 flex items-center justify-center" style="width: 44px; height: 44px; border-radius: 9999px; background-color: #fef3c7;">
                        <x-storefront.icon name="close" class="w-5 h-5" style="color: #b45309;" />
                    </div>
                    <h3 class="x4-heading-md mb-1" style="color: #92400e;">No Orders Found</h3>
                    <p class="x4-body-md" style="color: #92400e;">We couldn't find any orders for this phone number. Please check and try again.</p>
                </div>
            </template>

            {{-- ============================================================
                 Orders list
                 ============================================================ --}}
            <template x-if="orders.length > 0">
                <div class="mb-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="x4-heading-md" style="color: var(--x4-ink);">Your Orders</h2>
                        <span class="x4-caption" style="color: var(--x4-ink-mute);" x-text="`${orders.length} order(s) found`"></span>
                    </div>

                    <div class="space-y-3">
                        <template x-for="order in orders" :key="order.id">
                            <div class="x4-panel" style="overflow: hidden;">
                                <div class="flex items-center justify-between gap-3" style="padding: 16px 20px; border-bottom: 1px solid var(--x4-hairline);">
                                    <div>
                                        <span class="x4-micro-cap" style="color: var(--x4-ink-mute);">Order Reference</span>
                                        <p class="x4-tnum" style="font-size: 14px; font-weight: 500; color: var(--x4-ink);" x-text="order.reference"></p>
                                    </div>
                                    <div class="inline-flex items-center flex-shrink-0" :class="order.status_color.bg + ' ' + order.status_color.text" style="padding: 5px 12px; border-radius: var(--x4-r-pill);">
                                        <span class="w-1.5 h-1.5 rounded-full mr-2" :class="order.status_color.dot"></span>
                                        <span class="x4-caption" style="font-weight: 500;" x-text="order.status_label"></span>
                                    </div>
                                </div>

                                <div class="grid grid-cols-2 gap-4" style="padding: 16px 20px;">
                                    <div>
                                        <span class="x4-micro-cap" style="color: var(--x4-ink-mute);">Service</span>
                                        <p class="x4-caption truncate" style="font-weight: 500; color: var(--x4-ink); margin-top: 2px;" x-text="order.service"></p>
                                    </div>
                                    <div>
                                        <span class="x4-micro-cap" style="color: var(--x4-ink-mute);">Amount</span>
                                        <p class="x4-caption x4-tnum" style="font-weight: 500; color: var(--x4-ink); margin-top: 2px;">GH₵ <span x-text="order.amount"></span></p>
                                    </div>
                                    <div>
                                        <span class="x4-micro-cap" style="color: var(--x4-ink-mute);">Vendor</span>
                                        <p class="x4-caption" style="font-weight: 500; color: var(--x4-ink); margin-top: 2px;" x-text="order.vendor_name"></p>
                                    </div>
                                    <div>
                                        <span class="x4-micro-cap" style="color: var(--x4-ink-mute);">Date</span>
                                        <p class="x4-caption" style="font-weight: 500; color: var(--x4-ink); margin-top: 2px;" x-text="order.date"></p>
                                    </div>
                                </div>

                                <div class="flex items-center justify-between gap-3" style="padding: 12px 20px; background-color: var(--x4-canvas-soft); border-top: 1px solid var(--x4-hairline);">
                                    <span class="x4-caption flex items-center gap-1.5" style="color: var(--x4-ink-mute);">
                                        <x-storefront.icon name="clock" class="w-3.5 h-3.5" />
                                        <span x-text="order.time"></span>
                                    </span>
                                    <span class="x4-caption" style="color: var(--x4-ink-mute);">
                                        Updated <span style="font-weight: 500; color: var(--x4-ink-sec);" x-text="order.updated_at"></span>
                                    </span>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </template>

            {{-- ============================================================
                 Status legend
                 ============================================================ --}}
            <x-storefront.reveal :delay="100">
            <div class="x4-panel mb-8" style="padding: 20px 24px;">
                <p class="x4-micro-cap mb-4" style="color: var(--x4-ink-mute);">Status Guide</p>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    @foreach ([
                        ['label' => 'Pending', 'dot' => '#eab308'],
                        ['label' => 'Processing', 'dot' => '#3b82f6'],
                        ['label' => 'Completed', 'dot' => '#22c55e'],
                        ['label' => 'Cancelled', 'dot' => '#ef4444'],
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
@endsection

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
