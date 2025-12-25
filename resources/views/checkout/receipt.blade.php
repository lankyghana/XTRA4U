<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Receipt - Order #{{ $order->id }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111827; }
        .header { margin-bottom: 18px; }
        .title { font-size: 18px; font-weight: 700; margin: 0 0 4px; }
        .muted { color: #6B7280; }
        .box { border: 1px solid #E5E7EB; border-radius: 8px; padding: 12px; }
        .row { display: table; width: 100%; }
        .col { display: table-cell; vertical-align: top; width: 50%; }
        .label { color: #6B7280; width: 140px; display: inline-block; }
        .value { font-weight: 600; }
        .spacer { height: 10px; }
        .total { font-size: 14px; font-weight: 700; }
        .divider { border-top: 1px solid #E5E7EB; margin: 12px 0; }
    </style>
</head>
<body>
    <div class="header">
        <p class="title">XTRA4U Receipt</p>
        <p class="muted">Order #{{ $order->id }} • {{ $order->created_at?->format('M d, Y g:i A') }}</p>
    </div>

    <div class="box">
        <div class="row">
            <div class="col">
                <p><span class="label">Vendor:</span> <span class="value">{{ $order->vendor?->business_name ?? $order->vendor?->name ?? 'N/A' }}</span></p>
                <p><span class="label">Product:</span> <span class="value">{{ $order->display_product_label }}</span></p>
                <p><span class="label">Recipient:</span> <span class="value">{{ $order->recipient_phone_number }}</span></p>
            </div>
            <div class="col">
                <p><span class="label">Payment Method:</span> <span class="value">Mobile Money</span></p>
                <p><span class="label">MoMo Number:</span> <span class="value">{{ $order->mobile_money_number }}</span></p>
                <p><span class="label">Status:</span> <span class="value">{{ $order->status }}</span></p>
            </div>
        </div>

        <div class="divider"></div>

        <p class="total"><span class="label">Amount Paid:</span> GHS {{ number_format((float) $order->amount_paid, 2) }}</p>
        @if(!empty($order->payment_reference))
            <div class="spacer"></div>
            <p><span class="label">Reference:</span> <span class="value">{{ $order->payment_reference }}</span></p>
        @endif
        @if(!empty($order->payment_gateway))
            <p><span class="label">Gateway:</span> <span class="value">{{ $order->payment_gateway }}</span></p>
        @endif
    </div>
</body>
</html>
