@component('mail::message')
# Your USSD service is live

Hi {{ $vendor->name }},

Your **{{ $subscription->plan?->name }}** USSD plan is now active.

{{-- Backticks are required: a USSD code is full of `*` and `#`, which Markdown
     would otherwise consume as emphasis and heading syntax. --}}
**Your USSD code:** `{{ $subscription->currentUssdCode() }}`

Share this code with your customers — dialling it takes them straight to your store.

- **Sessions included:** {{ number_format($subscription->total_sessions) }}
@if ($subscription->carried_over_sessions > 0)
- **Carried over from your previous plan:** {{ number_format($subscription->carried_over_sessions) }}
@endif
- **Expires:** {{ $subscription->expires_at?->format('d M Y') }}

@component('mail::button', ['url' => route('vendor.ussd.subscription.index')])
View Subscription
@endcomponent

Thanks,
XTRA4U
@endcomponent
