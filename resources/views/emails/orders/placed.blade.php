<x-mail::message>
# {{ $vendorRole === 'owner' ? 'Your Product Was Sold!' : 'New Order Received!' }}

Hello **{{ $vendor->name }}**,

@if($vendorRole === 'owner')
Great news! A reseller has successfully sold your product. Here are the order details:
@elseif($vendorRole === 'reseller')
You have received a new order through your reseller store. Here are the details:
@else
You have received a new order! Here are the details:
@endif

---

## Order Details

<x-mail::table>
| Detail | Information |
|:-------|:------------|
| **Order ID** | #{{ $order->id }} |
| **Product** | {{ $order->service_purchased }} |
| **Customer Phone** | {{ $order->recipient_phone_number }} |
| **Total Amount** | GHS {{ number_format($order->amount_paid, 2) }} |
| **Your Earning** | GHS {{ number_format($vendorEarning, 2) }} |
| **Order Status** | {{ ucfirst($order->status) }} |
| **Order Date** | {{ $order->created_at->format('M d, Y \a\t h:i A') }} |
</x-mail::table>

@if($order->is_reseller_order && $vendorRole === 'owner')
<x-mail::panel>
**Affiliate Order Information**

This order was placed through a reseller. Your product was sold at the base price of **GHS {{ number_format($order->base_price, 2) }}**.

The reseller added a markup of **GHS {{ number_format($order->markup_price, 2) }}** to the final price.

*As the product owner, you need to fulfill this order.*
</x-mail::panel>
@endif

@if($order->is_reseller_order && $vendorRole === 'reseller')
<x-mail::panel>
**Reseller Order Information**

You sold this product at **GHS {{ number_format($order->amount_paid, 2) }}** (Base: GHS {{ number_format($order->base_price, 2) }} + Your Markup: GHS {{ number_format($order->markup_price, 2) }}).

*The product owner (Vendor B) will fulfill this order.*
</x-mail::panel>
@endif

<x-mail::button :url="route('vendor.orders.index')" color="primary">
View Order Details
</x-mail::button>

---

@if($vendorRole === 'owner' && $order->is_reseller_order)
**Important:** Please process this order promptly to maintain a good relationship with your resellers.
@else
**Tip:** Respond quickly to orders to maintain great customer satisfaction!
@endif

Thanks for being part of XTRA4U!

Best regards,<br>
**{{ config('mail.from.name', config('app.name')) }} Team**

<x-mail::subcopy>
If you have any questions about this order, please contact our support team.
</x-mail::subcopy>
</x-mail::message>
