@component('mail::message')
# Your USSD subscription expires soon

Hi {{ $vendor->name }},

Your **{{ $subscription->plan?->name }}** USSD plan expires on
**{{ $subscription->expires_at?->format('d M Y') }}** — that is
{{ $subscription->remainingDays() }} {{ Str::plural('day', $subscription->remainingDays()) }} from now.

When it expires, customers dialling `{{ $subscription->currentUssdCode() }}` will no longer reach your store.

You still have **{{ number_format($subscription->remaining_sessions) }}** unused sessions.
Renew before expiry and they carry over to your new plan.

@component('mail::button', ['url' => route('vendor.ussd.subscription.index')])
Renew Now
@endcomponent

Thanks,
XTRA4U
@endcomponent
