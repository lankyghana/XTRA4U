@component('mail::message')
@if (($context['threshold'] ?? 0) >= 100)
# Your USSD sessions are exhausted
@else
# Your USSD sessions are running low
@endif

Hi {{ $vendor->name }},

You have used **{{ $context['threshold'] ?? $subscription->usage_percentage }}%** of the
{{ number_format($subscription->total_sessions) }} sessions on your
**{{ $subscription->plan?->name }}** plan.

- **Used:** {{ number_format($subscription->used_sessions) }}
- **Remaining:** {{ number_format($subscription->remaining_sessions) }}

@if ($subscription->remaining_sessions <= 0)
Customers dialling `{{ $subscription->metadata['released_ussd_code'] ?? $subscription->ussd_code }}` can no longer reach your store. Renew or upgrade to restore service immediately.
@else
Once they run out, customers will no longer be able to dial through to your store.
@endif

@component('mail::button', ['url' => route('vendor.ussd.subscription.index')])
Top Up Your Sessions
@endcomponent

Thanks,
XTRA4U
@endcomponent
