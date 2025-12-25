<x-mail::message>
# @if($type === 'paid') Withdrawal Paid @elseif($type === 'failed') Withdrawal Failed @else Withdrawal Request Received @endif

Hello **{{ $vendor->name }}**,

@if($type === 'paid')
Your withdrawal has been **paid successfully**.
@elseif($type === 'failed')
Your withdrawal **failed** and has been **refunded to your wallet**.
@else
We have received your withdrawal request and it is being processed.
@endif

---

## Withdrawal Details

<x-mail::table>
| Detail | Information |
|:-------|:------------|
| **Amount** | GHS {{ number_format((float) $withdrawal->amount, 2) }} |
| **MoMo Number** | {{ $withdrawal->momo_number }} |
| **Network** | {{ $withdrawal->momo_network }} |
| **Reference** | {{ $withdrawal->payout_reference ?? $withdrawal->reference }} |
| **Status** | {{ ucfirst((string) ($withdrawal->payout_status ?? $withdrawal->status)) }} |
</x-mail::table>

@if(!empty($details))
<x-mail::panel>
{{ $details }}
</x-mail::panel>
@endif

---

Best regards,<br>
**{{ config('mail.from.name', config('app.name')) }} Team**

<x-mail::subcopy>
If you have questions, contact support.
</x-mail::subcopy>
</x-mail::message>
