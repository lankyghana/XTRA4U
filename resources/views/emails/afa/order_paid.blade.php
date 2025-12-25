<x-mail::message>
# New AFA Order - Payment Confirmed

Hello **{{ $vendor->name }}**,

@if($vendorRole === 'owner')
Great news! A reseller has successfully sold your AFA service. Payment is confirmed and this registration is now ready for fulfillment.
@elseif($vendorRole === 'reseller')
You’ve successfully sold an AFA registration. Payment is confirmed. The source vendor will handle fulfillment.
@else
You’ve received a new AFA registration. Payment is confirmed and it’s ready for fulfillment.
@endif

---

## Registration Details

<x-mail::table>
| Detail | Information |
|:-------|:------------|
| **Reference** | {{ $registration->reference }} |
| **Full Name** | {{ $registration->full_name }} |
| **Customer Phone** | {{ $registration->phone_number }} |
| **Region** | {{ $registration->region }} |
| **Location** | {{ $registration->location }} |
| **ID Type** | {{ $registration->id_type_label }} |
| **ID Number** | {{ $registration->id_number }} |
| **Amount Paid** | GHS {{ number_format((float) $registration->amount, 2) }} |
| **Your Earning** | GHS {{ number_format((float) $vendorEarning, 2) }} |
| **Payment Status** | {{ ucfirst((string) $registration->payment_status) }} |
| **Fulfillment Status** | {{ $registration->status_label }} |
| **Order Date** | {{ $registration->created_at->format('M d, Y \a\t h:i A') }} |
</x-mail::table>

@if($registration->is_reseller_order && $vendorRole === 'owner')
<x-mail::panel>
**Affiliate Order Information**

This registration was sold through a reseller.

*As the service owner, you need to fulfill this registration.*
</x-mail::panel>
@endif

@if($registration->is_reseller_order && $vendorRole === 'reseller')
<x-mail::panel>
**Reseller Order Information**

You sold this registration through your storefront.

*The source vendor will fulfill this registration.*
</x-mail::panel>
@endif

<x-mail::button :url="route('vendor.afa.show', $registration)" color="primary">
View AFA Registration
</x-mail::button>

---

Best regards,<br>
**{{ config('mail.from.name', config('app.name')) }} Team**

<x-mail::subcopy>
If you have questions, contact support.
</x-mail::subcopy>
</x-mail::message>
