@component('mail::message')
# Wallet Top-up Successful

Hi {{ $vendor->name }},

Your wallet has been topped up with GHS {{ number_format($amount, 2) }}.

Reference: {{ $reference }}

Thanks,
XTRA4U
@endcomponent
