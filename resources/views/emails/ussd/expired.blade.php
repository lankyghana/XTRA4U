@component('mail::message')
# Your USSD subscription has expired

Hi {{ $vendor->name }},

Your **{{ $subscription->plan?->name }}** USSD plan expired on
{{ $subscription->expires_at?->format('d M Y') }}, and customers can no longer dial through to your store.

@if ($subscription->remaining_sessions > 0)
You had {{ number_format($subscription->remaining_sessions) }} unused
{{ Str::plural('session', $subscription->remaining_sessions) }}. Renew now and they carry over to your new plan.
@endif

@component('mail::button', ['url' => route('vendor.ussd.subscription.index')])
Renew Subscription
@endcomponent

Thanks,
XTRA4U
@endcomponent
