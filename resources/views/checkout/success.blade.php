{{--
    checkout/success.blade.php

    Reached via CheckoutController@success after a completed payment
    (route: GET /checkout/success/{order}). Presentation only — every
    field read here already comes loaded on $order by the controller
    (service, vendor, ownerVendor, resellerVendor), so no extra queries
    are introduced. The status label/colour mapping mirrors
    OrderStatusController::getStatusLabel()/getStatusColor() exactly, so
    the badge matches what "Track Your Order" shows elsewhere for the
    same order.
--}}
@extends('layouts.app')

@section('title', 'Payment Successful - XTRA4U')
@section('description', 'Your payment has been processed successfully')

{{-- Scope the storefront design system to this page only. --}}
@section('body-class', 'x4')

@php
    $shopUrl = $order->vendor
        ? route('storefront.vendor', ['vendor' => $order->vendor->vendor_code])
        : route('checkout.show');

    $statusKey = strtolower($order->status ?? '');

    // Mirrors OrderStatusController::getStatusLabel() / getStatusColor() —
    // duplicated here (rather than reused) because that method lives on a
    // controller and returns Tailwind class names, which is exactly the
    // presentation-only mapping this page needs too.
    $statusLabel = match ($statusKey) {
        'pending' => 'Pending',
        'processing' => 'Processing',
        'completed' => 'Completed',
        'cancelled', 'canceled' => 'Cancelled',
        'failed' => 'Failed',
        'refunded' => 'Refunded',
        'on hold' => 'On Hold',
        'verifying' => 'Verifying',
        default => $order->status ? ucfirst($order->status) : 'Unknown',
    };

    $statusColor = match ($statusKey) {
        'pending' => ['bg' => 'bg-yellow-100', 'text' => 'text-yellow-800'],
        'processing' => ['bg' => 'bg-blue-100', 'text' => 'text-blue-800'],
        'completed' => ['bg' => 'bg-green-100', 'text' => 'text-green-800'],
        'cancelled', 'canceled' => ['bg' => 'bg-red-100', 'text' => 'text-red-800'],
        'failed' => ['bg' => 'bg-red-100', 'text' => 'text-red-800'],
        'refunded' => ['bg' => 'bg-purple-100', 'text' => 'text-purple-800'],
        'on hold' => ['bg' => 'bg-orange-100', 'text' => 'text-orange-800'],
        'verifying' => ['bg' => 'bg-indigo-100', 'text' => 'text-indigo-800'],
        default => ['bg' => 'bg-gray-100', 'text' => 'text-gray-800'],
    };

    // The payment that brought the customer to this page already
    // succeeded; what varies is where *fulfillment* of the order has
    // gotten to since. Reflect that honestly instead of always claiming
    // "Processing".
    $isFulfilled = $statusKey === 'completed';
    $needsAttention = in_array($statusKey, ['failed', 'cancelled', 'canceled', 'refunded'], true);

    $vendorDisplayName = $order->vendor->business_name ?? $order->vendor->name ?? 'the vendor';
    $vendorPhone = $order->vendor->phone_number ?? null;
    $whatsappChannel = 'https://whatsapp.com/channel/0029Vb6ZXJuL7UVQeZ7L5D3v';
    $supportHref = $vendorPhone
        ? 'https://wa.me/' . preg_replace('/[^0-9]/', '', $vendorPhone)
        : $whatsappChannel;
@endphp

@section('site-header')
    <x-storefront.header :shop-url="$shopUrl" />
@endsection

@section('site-footer')
    <x-storefront.footer :shop-url="$shopUrl" />
@endsection

@section('content')
<div class="x4-page" style="padding-top: 64px;">
    <section class="relative overflow-hidden" style="background: #fff;">
        <div class="x4-hero-wash absolute inset-0" aria-hidden="true" style="pointer-events: none;"></div>

        <div class="relative max-w-3xl mx-auto px-5" style="padding-top: 56px; padding-bottom: 48px;">

            {{-- ============================================================
                 Success header
                 ============================================================ --}}
            <x-storefront.reveal from="up" class="text-center mb-10">
                <div
                    class="mx-auto mb-6 flex items-center justify-center"
                    style="width: 76px; height: 76px; border-radius: 9999px; background-color: #dcfce7;"
                >
                    <x-storefront.icon name="check" class="w-9 h-9" style="color: #16a34a;" />
                </div>

                <h1 class="x4-display-xl mb-3" style="color: var(--x4-ink-strong);">Payment Successful!</h1>
                <p class="x4-body-lg" style="color: var(--x4-ink-body);">
                    Your payment went through — here's what happens with your order next.
                </p>
            </x-storefront.reveal>

            {{-- ============================================================
                 Order details
                 ============================================================ --}}
            <x-storefront.reveal :delay="80">
            <div class="x4-panel mb-6" style="overflow: hidden;">
                <div style="padding: 18px 24px; border-bottom: 1px solid var(--x4-hairline); background-color: var(--x4-canvas-soft);">
                    <h2 class="x4-heading-md" style="color: var(--x4-ink);">Order Details</h2>
                    <p class="x4-caption mt-0.5" style="color: var(--x4-ink-mute);">
                        Order #{{ $order->id }} &middot; {{ $order->created_at?->format('M d, Y \a\t g:i A') }}
                    </p>
                </div>

                <div style="padding: 24px;" class="grid grid-cols-1 sm:grid-cols-2 gap-8">
                    <div>
                        <p class="x4-micro-cap mb-3" style="color: var(--x4-ink-mute);">Service Information</p>
                        <div class="space-y-2.5">
                            <div class="flex justify-between gap-4 x4-caption">
                                <span style="color: var(--x4-ink-mute);">Vendor</span>
                                <span style="color: var(--x4-ink); font-weight: 500; text-align: right;">{{ $vendorDisplayName }}</span>
                            </div>
                            <div class="flex justify-between gap-4 x4-caption">
                                <span style="color: var(--x4-ink-mute);">Service</span>
                                <span style="color: var(--x4-ink); font-weight: 500; text-align: right;">{{ $order->display_product_label }}</span>
                            </div>
                            <div class="flex justify-between gap-4 x4-caption">
                                <span style="color: var(--x4-ink-mute);">Recipient</span>
                                <span class="x4-tnum" style="color: var(--x4-ink); font-weight: 500;">{{ $order->recipient_phone_number }}</span>
                            </div>
                            <div class="flex justify-between gap-4 x4-caption">
                                <span style="color: var(--x4-ink-mute);">Amount</span>
                                <span class="x4-tnum" style="color: #16a34a; font-weight: 500;">GH₵ {{ number_format((float) $order->amount_paid, 2) }}</span>
                            </div>
                        </div>
                    </div>

                    <div>
                        <p class="x4-micro-cap mb-3" style="color: var(--x4-ink-mute);">Payment Information</p>
                        <div class="space-y-2.5">
                            <div class="flex justify-between gap-4 x4-caption">
                                <span style="color: var(--x4-ink-mute);">Payment Method</span>
                                <span style="color: var(--x4-ink); font-weight: 500;">Mobile Money</span>
                            </div>
                            @if ($order->mobile_money_number)
                                <div class="flex justify-between gap-4 x4-caption">
                                    <span style="color: var(--x4-ink-mute);">Mobile Money</span>
                                    <span class="x4-tnum" style="color: var(--x4-ink); font-weight: 500;">{{ $order->mobile_money_number }}</span>
                                </div>
                            @endif
                            @if ($order->payment_reference)
                                <div class="flex justify-between gap-4 x4-caption">
                                    <span style="color: var(--x4-ink-mute);">Reference</span>
                                    <span class="x4-tnum" style="color: var(--x4-ink); font-weight: 500;">{{ $order->payment_reference }}</span>
                                </div>
                            @endif
                            <div class="flex justify-between items-center gap-4 x4-caption">
                                <span style="color: var(--x4-ink-mute);">Order Status</span>
                                <span class="inline-flex items-center {{ $statusColor['bg'] }} {{ $statusColor['text'] }}" style="padding: 3px 10px; border-radius: var(--x4-r-pill); font-size: 12px; font-weight: 500;">
                                    {{ $statusLabel }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ============================================================
                     Order progress — reflects $order->status, not a fixed step.
                     ============================================================ --}}
                <div style="padding: 20px 24px 24px; border-top: 1px solid var(--x4-hairline);">
                    <p class="x4-micro-cap mb-4" style="color: var(--x4-ink-mute);">Order Progress</p>

                    <div class="flex items-center">
                        {{-- Step 1: payment — always done, this page is only reached after a successful payment. --}}
                        <div class="flex items-center gap-2 flex-shrink-0">
                            <span class="x4-tnum flex items-center justify-center flex-shrink-0" style="width: 30px; height: 30px; border-radius: 9999px; background-color: #16a34a; color: #fff; font-size: 13px;">
                                <x-storefront.icon name="check" class="w-3.5 h-3.5" />
                            </span>
                            <span class="x4-caption hidden sm:inline" style="color: #16a34a; font-weight: 500;">Payment Received</span>
                        </div>

                        <div class="flex-1 mx-2" style="height: 2px; background-color: {{ $isFulfilled || ! $needsAttention ? '#bbf7d0' : '#fecaca' }};"></div>

                        {{-- Step 2: fulfillment in progress / issue / done. --}}
                        <div class="flex items-center gap-2 flex-shrink-0">
                            @if ($needsAttention)
                                <span class="x4-tnum flex items-center justify-center flex-shrink-0" style="width: 30px; height: 30px; border-radius: 9999px; background-color: #dc2626; color: #fff; font-size: 13px;">
                                    <x-storefront.icon name="close" class="w-3.5 h-3.5" />
                                </span>
                                <span class="x4-caption hidden sm:inline" style="color: #dc2626; font-weight: 500;">Needs Attention</span>
                            @elseif ($isFulfilled)
                                <span class="x4-tnum flex items-center justify-center flex-shrink-0" style="width: 30px; height: 30px; border-radius: 9999px; background-color: #16a34a; color: #fff; font-size: 13px;">
                                    <x-storefront.icon name="check" class="w-3.5 h-3.5" />
                                </span>
                                <span class="x4-caption hidden sm:inline" style="color: #16a34a; font-weight: 500;">Processing</span>
                            @else
                                <span class="x4-tnum flex items-center justify-center flex-shrink-0" style="width: 30px; height: 30px; border-radius: 9999px; background-color: var(--x4-violet); color: #fff; font-size: 13px; box-shadow: 0 0 0 4px var(--x4-violet-soft);">2</span>
                                <span class="x4-caption hidden sm:inline" style="color: var(--x4-violet); font-weight: 500;">Processing</span>
                            @endif
                        </div>

                        <div class="flex-1 mx-2" style="height: 2px; background-color: {{ $isFulfilled ? '#bbf7d0' : 'var(--x4-hairline)' }};"></div>

                        {{-- Step 3: delivered. --}}
                        <div class="flex items-center gap-2 flex-shrink-0">
                            @if ($isFulfilled)
                                <span class="x4-tnum flex items-center justify-center flex-shrink-0" style="width: 30px; height: 30px; border-radius: 9999px; background-color: #16a34a; color: #fff; font-size: 13px;">
                                    <x-storefront.icon name="check" class="w-3.5 h-3.5" />
                                </span>
                                <span class="x4-caption hidden sm:inline" style="color: #16a34a; font-weight: 500;">Completed</span>
                            @else
                                <span class="x4-tnum flex items-center justify-center flex-shrink-0" style="width: 30px; height: 30px; border-radius: 9999px; background-color: var(--x4-canvas-soft); color: var(--x4-ink-mute); font-size: 13px; border: 1px solid var(--x4-hairline);">3</span>
                                <span class="x4-caption hidden sm:inline" style="color: var(--x4-ink-mute);">Completed</span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
            </x-storefront.reveal>

            {{-- ============================================================
                 What happens next / needs-attention notice
                 ============================================================ --}}
            @if ($needsAttention)
                <x-storefront.reveal :delay="120">
                <div class="x4-panel mb-6" style="padding: 24px; border-color: #fecaca; background-color: #fef2f2;">
                    <h3 class="x4-heading-md mb-2" style="color: #991b1b;">This order needs attention</h3>
                    <p class="x4-body-md" style="color: #7f1d1d;">
                        Your payment was received, but this order is currently marked
                        <strong>{{ strtolower($statusLabel) }}</strong>. Contact {{ $vendorDisplayName }} using the
                        button below and they'll help sort it out.
                    </p>
                </div>
                </x-storefront.reveal>
            @else
                <x-storefront.reveal :delay="120">
                <div class="x4-panel mb-6" style="padding: 24px;">
                    <h3 class="x4-heading-md mb-4" style="color: var(--x4-ink);">What happens next?</h3>

                    <div class="space-y-4">
                        @foreach ([
                            ['n' => '1', 'title' => 'Processing', 'body' => $isFulfilled ? $vendorDisplayName . ' has processed your order.' : $vendorDisplayName . ' will process your order within the next few minutes.'],
                            ['n' => '2', 'title' => 'Service Delivery', 'body' => 'The service will be delivered to ' . $order->recipient_phone_number . '.'],
                            ['n' => '3', 'title' => 'Confirmation', 'body' => "You'll receive an SMS confirmation once the service is delivered."],
                        ] as $step)
                            <div class="flex items-start gap-3">
                                <span class="x4-tnum flex items-center justify-center flex-shrink-0" style="width: 24px; height: 24px; border-radius: 9999px; background-color: var(--x4-violet-soft); color: var(--x4-violet); font-size: 11px;">{{ $step['n'] }}</span>
                                <p class="x4-body-md" style="color: var(--x4-ink-sec);">
                                    <strong style="color: var(--x4-ink); font-weight: 500;">{{ $step['title'] }}:</strong> {{ $step['body'] }}
                                </p>
                            </div>
                        @endforeach
                    </div>
                </div>
                </x-storefront.reveal>
            @endif

            {{-- ============================================================
                 Contact support
                 ============================================================ --}}
            <x-storefront.reveal :delay="160">
            <div class="x4-panel mb-8" style="padding: 24px; background-color: var(--x4-violet-soft); border-color: var(--x4-violet-soft-deep);">
                <div class="flex items-start gap-3.5">
                    <span class="flex items-center justify-center flex-shrink-0" style="width: 36px; height: 36px; border-radius: var(--x4-r-md); background-color: var(--x4-canvas);">
                        <x-storefront.icon name="phone" class="w-4 h-4" style="color: var(--x4-violet);" />
                    </span>
                    <div>
                        <h3 class="x4-heading-md mb-1" style="color: var(--x4-primary-deep);">Need Help?</h3>
                        <p class="x4-body-md mb-4" style="color: var(--x4-ink-sec);">
                            If you have any questions about your order or need assistance, {{ $vendorDisplayName }} is here to help.
                        </p>
                        <a
                            href="{{ $supportHref }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="x4-btn x4-btn-outline"
                        >
                            <x-storefront.icon name="whatsapp" class="w-4 h-4" />
                            Contact Support
                        </a>
                    </div>
                </div>
            </div>
            </x-storefront.reveal>

            {{-- ============================================================
                 Actions
                 ============================================================ --}}
            <x-storefront.reveal :delay="200" class="flex flex-col sm:flex-row gap-3 justify-center">
                <x-storefront.btn :href="$shopUrl" variant="primary" class="justify-center">
                    Continue Shopping
                    <x-storefront.icon name="arrow" class="w-4 h-4" />
                </x-storefront.btn>
                <x-storefront.btn :href="route('checkout.receipt', $order)" variant="outline" class="justify-center" target="_blank" rel="noopener">
                    Print Receipt
                </x-storefront.btn>
            </x-storefront.reveal>
        </div>
    </section>
</div>
@endsection
