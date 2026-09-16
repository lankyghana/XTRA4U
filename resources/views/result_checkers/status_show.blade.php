{{--
    result_checkers/status_show.blade.php

    Single result-checker order, reached via GET /results-checker/status/{order}
    — ResultCheckerPaymentCallbackController redirects here after payment.
    Everything needed is already known server-side ($order, loaded with its
    service), so this is now plain Blade with no Alpine/JS: the previous
    version drove the status colours through `:class="$order->status ? ...`
    Alpine bindings referencing a bare `$order` PHP variable inside a JS
    expression, which Alpine can never resolve (it isn't a JS identifier) —
    those bindings silently failed. The label/colour/message mapping below
    mirrors the same statuses ResultCheckerService::getOrderStatus() and
    result_checkers/status.blade.php use (pending_payment, pending_stock,
    processing, completed, failed).
--}}
@extends('layouts.app')

@section('title', 'Order Status - ' . $order->id . ' - XTRA4U')

{{-- Scope the storefront design system to this page only. --}}
@section('body-class', 'x4')

@php
    $mainStoreVendor = \App\Support\MainStore::vendor();
    $shopUrl = $mainStoreVendor
        ? route('storefront.vendor', ['vendor' => $mainStoreVendor->vendor_code])
        : route('checkout.show');

    $statusMeta = match ($order->status) {
        'pending_payment' => ['label' => 'Awaiting Payment', 'message' => 'Please complete your payment to proceed', 'bg' => '#fef9c3', 'text' => '#854d0e'],
        'pending_stock' => ['label' => 'Pending Stock', 'message' => 'We are preparing your results', 'bg' => '#ffedd5', 'text' => '#9a3412'],
        'processing' => ['label' => 'Processing', 'message' => 'Your order is being processed', 'bg' => '#dbeafe', 'text' => '#1e40af'],
        'completed' => ['label' => 'Completed', 'message' => 'Your order has been completed', 'bg' => '#dcfce7', 'text' => '#166534'],
        'failed' => ['label' => 'Failed', 'message' => 'Your order could not be completed', 'bg' => '#fee2e2', 'text' => '#991b1b'],
        default => ['label' => 'Unknown', 'message' => 'Check your order status below', 'bg' => '#f3f4f6', 'text' => '#374151'],
    };
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

        <div class="relative max-w-3xl mx-auto px-5" style="padding-top: 56px; padding-bottom: 72px;">

            {{-- ============================================================
                 Header
                 ============================================================ --}}
            <x-storefront.reveal from="up" class="text-center mb-9">
                <div
                    class="mx-auto mb-5 flex items-center justify-center"
                    style="width: 64px; height: 64px; border-radius: var(--x4-r-lg); background-color: {{ $statusMeta['bg'] }};"
                >
                    <x-storefront.icon name="check" class="w-7 h-7" style="color: {{ $statusMeta['text'] }};" />
                </div>

                <h1 class="x4-display-xl mb-3" style="color: var(--x4-ink-strong);">Order Status</h1>
                <p class="x4-body-lg" style="color: var(--x4-ink-body);">{{ $statusMeta['message'] }}</p>
            </x-storefront.reveal>

            {{-- ============================================================
                 Payment verification failure — ResultCheckerPaymentCallbackController
                 flashes these two keys when the gateway couldn't confirm payment.
                 ============================================================ --}}
            @if (session('payment_failed'))
                <x-storefront.reveal :delay="30">
                <div class="mb-6" style="background-color: #fef2f2; border: 1px solid #fecaca; border-radius: var(--x4-r-lg); padding: 16px 18px;">
                    <div class="flex items-start gap-3">
                        <x-storefront.icon name="close" class="w-4 h-4 flex-shrink-0 mt-0.5" style="color: #dc2626;" />
                        <div>
                            <p class="x4-body-md" style="color: #991b1b; font-weight: 500;">We couldn't confirm your payment</p>
                            <p class="x4-caption mt-0.5" style="color: #991b1b;">{{ session('payment_message') ?? 'Payment verification failed. Please try again or contact support.' }}</p>
                        </div>
                    </div>
                </div>
                </x-storefront.reveal>
            @endif

            {{-- ============================================================
                 Status card
                 ============================================================ --}}
            <x-storefront.reveal :delay="60">
            <div class="x4-panel mb-8" style="overflow: hidden;">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3" style="padding: 20px 24px; border-bottom: 1px solid var(--x4-hairline); background-color: var(--x4-canvas-soft);">
                    <div>
                        <span class="x4-micro-cap" style="color: var(--x4-ink-mute);">Order ID</span>
                        <p class="x4-tnum" style="font-size: 16px; font-weight: 500; color: var(--x4-ink);">{{ $order->id }}</p>
                    </div>
                    <span
                        class="inline-flex items-center flex-shrink-0"
                        style="padding: 5px 14px; border-radius: var(--x4-r-pill); background-color: {{ $statusMeta['bg'] }}; color: {{ $statusMeta['text'] }};"
                    >
                        <span class="x4-caption" style="font-weight: 500;">{{ $statusMeta['label'] }}</span>
                    </span>
                </div>

                {{-- Order information --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5" style="padding: 24px; border-bottom: 1px solid var(--x4-hairline);">
                    <div>
                        <span class="x4-micro-cap" style="color: var(--x4-ink-mute);">Service</span>
                        <p class="x4-body-md mt-1" style="font-weight: 500; color: var(--x4-ink);">{{ $order->service->name ?? 'N/A' }}</p>
                    </div>
                    <div>
                        <span class="x4-micro-cap" style="color: var(--x4-ink-mute);">Quantity</span>
                        <p class="x4-body-md mt-1" style="font-weight: 500; color: var(--x4-ink);">{{ $order->quantity }} item(s)</p>
                    </div>
                    <div>
                        <span class="x4-micro-cap" style="color: var(--x4-ink-mute);">Customer Name</span>
                        <p class="x4-body-md mt-1" style="font-weight: 500; color: var(--x4-ink);">{{ $order->customer_name ?? 'N/A' }}</p>
                    </div>
                    <div>
                        <span class="x4-micro-cap" style="color: var(--x4-ink-mute);">Phone Number</span>
                        <p class="x4-body-md x4-tnum mt-1" style="font-weight: 500; color: var(--x4-ink);">{{ $order->customer_phone }}</p>
                    </div>
                    <div>
                        <span class="x4-micro-cap" style="color: var(--x4-ink-mute);">Amount Paid</span>
                        <p class="x4-body-md x4-tnum mt-1" style="font-weight: 500; color: #16a34a;">GH₵ {{ number_format((float) $order->total_price, 2) }}</p>
                    </div>
                    <div>
                        <span class="x4-micro-cap" style="color: var(--x4-ink-mute);">Reference</span>
                        <p class="x4-caption x4-tnum mt-1" style="font-weight: 500; color: var(--x4-ink); word-break: break-all;">{{ $order->payment_reference }}</p>
                    </div>
                </div>

                {{-- Timeline --}}
                <div style="padding: 24px;">
                    <p class="x4-micro-cap mb-4" style="color: var(--x4-ink-mute);">Timeline</p>

                    <div class="space-y-0">
                        @foreach ([
                            ['title' => 'Order Created', 'done' => true, 'at' => $order->created_at, 'pending_label' => null],
                            ['title' => 'Payment Confirmed', 'done' => (bool) $order->paid_at, 'at' => $order->paid_at, 'pending_label' => 'Pending…'],
                            ['title' => 'Delivered', 'done' => (bool) $order->fulfilled_at, 'at' => $order->fulfilled_at, 'pending_label' => 'Processing…'],
                        ] as $i => $step)
                            <div class="flex items-start gap-4">
                                <div class="flex flex-col items-center flex-shrink-0">
                                    <span
                                        class="flex items-center justify-center"
                                        style="width: 34px; height: 34px; border-radius: 9999px; background-color: {{ $step['done'] ? '#dcfce7' : 'var(--x4-canvas-soft)' }}; border: 1px solid {{ $step['done'] ? '#bbf7d0' : 'var(--x4-hairline)' }};"
                                    >
                                        @if ($step['done'])
                                            <x-storefront.icon name="check" class="w-4 h-4" style="color: #16a34a;" />
                                        @else
                                            <span aria-hidden="true" style="width: 8px; height: 8px; border-radius: 9999px; background-color: var(--x4-ink-mute);"></span>
                                        @endif
                                    </span>
                                    @if ($i < 2)
                                        <span style="width: 2px; flex: 1; min-height: 28px; background-color: {{ $step['done'] ? '#bbf7d0' : 'var(--x4-hairline)' }}; margin: 4px 0;"></span>
                                    @endif
                                </div>
                                <div style="padding-bottom: 20px;">
                                    <p class="x4-body-md" style="font-weight: 500; color: var(--x4-ink);">{{ $step['title'] }}</p>
                                    <p class="x4-caption mt-0.5" style="color: var(--x4-ink-mute);">
                                        {{ $step['at']?->format('M d, Y h:i A') ?? $step['pending_label'] }}
                                    </p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Status-specific message --}}
                <div style="padding: 16px 24px; border-top: 1px solid var(--x4-hairline); background-color: {{ $statusMeta['bg'] }}1a;">
                    <p class="x4-body-md" style="color: {{ $statusMeta['text'] }};">
                        @switch ($order->status)
                            @case ('pending_payment')
                                Awaiting payment confirmation. Please complete the payment to proceed.
                                @break
                            @case ('pending_stock')
                                Your order is waiting for stock availability. We'll notify you via SMS when ready.
                                @break
                            @case ('completed')
                                Your results have been delivered to {{ $order->customer_phone }}. Check your SMS for details.
                                @break
                            @case ('failed')
                                This order could not be completed. Please contact support for assistance.
                                @break
                            @default
                                Your order is being processed.
                        @endswitch
                    </p>
                </div>
            </div>
            </x-storefront.reveal>

            {{-- ============================================================
                 Back button
                 ============================================================ --}}
            <div class="text-center">
                <a href="{{ route('result-checkers.status') }}" class="x4-caption x4-link x4-link-accent inline-flex items-center gap-2" style="color: var(--x4-ink-mute); font-weight: 500;">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                    </svg>
                    Back to Status Checker
                </a>
            </div>
        </div>
    </section>
</div>
@endsection
