@component('mail::message')
# Vendor Wallet Top-up

Vendor {{ $vendor->name }} (ID: {{ $vendor->id }}) topped up their wallet with GHS {{ number_format($amount, 2) }}.

Reference: {{ $reference }}

Regards,
XTRA4U
@endcomponent
