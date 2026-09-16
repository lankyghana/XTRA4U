<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Receipt - Order #{{ $order->id }}</title>
    <style>
        {{--
            Rendered by DomPDF (barryvdh/laravel-dompdf), not a browser —
            no CSS custom properties, no flexbox/grid, no gradients, no
            box-shadow. Two-column layout uses display:table/table-cell,
            the same technique the previous version of this file already
            relied on. Colours are the storefront's brand palette
            (resources/css/storefront.css --x4-* tokens), hard-coded as
            hex since dompdf does not resolve var().
        --}}
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            color: #0d253d;
            margin: 0;
        }
        .sheet { padding: 28px 34px; }

        .brand-bar { display: table; width: 100%; margin-bottom: 22px; }
        .brand-bar .brand-col { display: table-cell; vertical-align: middle; }
        .brand-mark {
            display: inline-block;
            width: 34px;
            height: 34px;
            background-color: #533afd;
            border-radius: 7px;
            color: #ffffff;
            font-size: 8px;
            font-weight: 700;
            letter-spacing: -0.3px;
            text-align: center;
            line-height: 30px;
        }
        .brand-name { font-size: 15px; font-weight: 700; color: #0d253d; padding-left: 8px; }
        .brand-sub { font-size: 9px; color: #8a94a6; letter-spacing: 0.06em; text-transform: uppercase; padding-left: 8px; }
        .brand-bar .meta-col { display: table-cell; vertical-align: middle; text-align: right; }
        .receipt-tag {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            background-color: #ede9fe;
            color: #4434d4;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .title-row { margin-bottom: 20px; }
        .title { font-size: 19px; font-weight: 700; color: #0d253d; margin: 0 0 3px; }
        .muted { color: #6b7280; }
        .order-meta { font-size: 10.5px; color: #6b7280; margin: 0; }

        .box {
            border: 1px solid #e3e8ee;
            border-radius: 10px;
            padding: 18px 20px;
        }
        .row { display: table; width: 100%; }
        .col { display: table-cell; vertical-align: top; width: 50%; }
        .col + .col { padding-left: 24px; }

        .section-label {
            font-size: 9px;
            font-weight: 700;
            color: #8a94a6;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin: 0 0 10px;
        }
        .field { margin: 0 0 9px; }
        .label { color: #6b7280; display: block; font-size: 10.5px; margin-bottom: 1px; }
        .value { font-weight: 700; color: #0d253d; font-size: 12px; }

        .status-pill {
            display: inline-block;
            padding: 2px 9px;
            border-radius: 999px;
            font-size: 10.5px;
            font-weight: 700;
        }

        .divider { border-top: 1px solid #e3e8ee; margin: 16px 0; }

        .total-row { display: table; width: 100%; }
        .total-row .label-col { display: table-cell; vertical-align: middle; }
        .total-row .amount-col { display: table-cell; vertical-align: middle; text-align: right; }
        .total-label { font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.06em; }
        .total-amount { font-size: 22px; font-weight: 700; color: #16a34a; }

        .extra-field { font-size: 10.5px; color: #4b5563; margin: 4px 0 0; }
        .extra-field .label { display: inline; font-size: 10.5px; }
        .extra-field .value { font-size: 10.5px; font-weight: 700; }

        .footer { margin-top: 26px; text-align: center; }
        .footer p { margin: 2px 0; font-size: 9.5px; color: #9aa4b2; }
        .footer .thanks { font-size: 11px; color: #4434d4; font-weight: 700; margin-bottom: 4px; }
    </style>
</head>
<body>
    <div class="sheet">
        <div class="brand-bar">
            <div class="brand-col">
                <span class="brand-mark">X4U</span>
                <span class="brand-name">XTRA<span style="color:#8a94a6;">4U</span></span>
                <br>
                <span class="brand-sub">Digital Services Marketplace</span>
            </div>
            <div class="meta-col">
                <span class="receipt-tag">Receipt</span>
            </div>
        </div>

        <div class="title-row">
            <p class="title">Payment Receipt</p>
            <p class="order-meta">Order #{{ $order->id }} &bull; {{ $order->created_at?->format('M d, Y g:i A') }}</p>
        </div>

        @php
            $statusKey = strtolower($order->status ?? '');
            // Mirrors the same status → colour mapping used on the storefront
            // (OrderStatusController::getStatusColor / checkout/success.blade.php),
            // as hex values since dompdf does not resolve CSS custom properties.
            $statusColor = match ($statusKey) {
                'pending' => ['bg' => '#fef9c3', 'text' => '#854d0e'],
                'processing' => ['bg' => '#dbeafe', 'text' => '#1e40af'],
                'completed' => ['bg' => '#dcfce7', 'text' => '#166534'],
                'cancelled', 'canceled' => ['bg' => '#fee2e2', 'text' => '#991b1b'],
                'failed' => ['bg' => '#fee2e2', 'text' => '#991b1b'],
                'refunded' => ['bg' => '#f3e8ff', 'text' => '#6b21a8'],
                'on hold' => ['bg' => '#ffedd5', 'text' => '#9a3412'],
                'verifying' => ['bg' => '#e0e7ff', 'text' => '#3730a3'],
                default => ['bg' => '#f3f4f6', 'text' => '#374151'],
            };
        @endphp

        <div class="box">
            <div class="row">
                <div class="col">
                    <p class="section-label">Order Information</p>
                    <div class="field">
                        <span class="label">Vendor</span>
                        <span class="value">{{ $order->vendor?->business_name ?? $order->vendor?->name ?? 'N/A' }}</span>
                    </div>
                    <div class="field">
                        <span class="label">Product</span>
                        <span class="value">{{ $order->display_product_label }}</span>
                    </div>
                    <div class="field">
                        <span class="label">Recipient</span>
                        <span class="value">{{ $order->recipient_phone_number }}</span>
                    </div>
                </div>
                <div class="col">
                    <p class="section-label">Payment Information</p>
                    <div class="field">
                        <span class="label">Payment Method</span>
                        <span class="value">Mobile Money</span>
                    </div>
                    <div class="field">
                        <span class="label">MoMo Number</span>
                        <span class="value">{{ $order->mobile_money_number }}</span>
                    </div>
                    <div class="field">
                        <span class="label">Status</span><br>
                        <span class="status-pill" style="background-color: {{ $statusColor['bg'] }}; color: {{ $statusColor['text'] }};">
                            {{ ucfirst($order->status ?? 'Unknown') }}
                        </span>
                    </div>
                </div>
            </div>

            <div class="divider"></div>

            <div class="total-row">
                <div class="label-col">
                    <span class="total-label">Amount Paid</span>
                    @if (!empty($order->payment_reference))
                        <p class="extra-field"><span class="label">Reference:</span> <span class="value">{{ $order->payment_reference }}</span></p>
                    @endif
                    @if (!empty($order->payment_gateway))
                        <p class="extra-field"><span class="label">Gateway:</span> <span class="value">{{ ucfirst($order->payment_gateway) }}</span></p>
                    @endif
                </div>
                <div class="amount-col">
                    <span class="total-amount">GHS {{ number_format((float) $order->amount_paid, 2) }}</span>
                </div>
            </div>
        </div>

        <div class="footer">
            <p class="thanks">Thank you for using XTRA4U</p>
            <p>Ghana's digital services marketplace &mdash; verified vendors, secure Mobile Money payments.</p>
            <p>Generated {{ now()->format('M d, Y g:i A') }} &bull; This receipt confirms payment; service delivery status is shown separately.</p>
        </div>
    </div>
</body>
</html>
