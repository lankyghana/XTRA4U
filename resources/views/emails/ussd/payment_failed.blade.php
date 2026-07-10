@component('mail::message')
# USSD subscription payment failed

Hi {{ $vendor->name }},

We could not complete your payment for the **{{ $subscription->plan?->name }}** USSD plan
(GHS {{ number_format($subscription->price_paid, 2) }}).

**No charge has been made**, and your subscription was not activated.

@if (! empty($context['reason']))
Reason: {{ $context['reason'] }}
@endif

@component('mail::button', ['url' => route('vendor.ussd.subscription.index')])
Try Again
@endcomponent

If the problem persists, please contact support.

Thanks,
XTRA4U
@endcomponent
