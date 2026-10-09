<?php

namespace App\Services\UtilityBills;

use App\Models\UtilityBillEvent;
use App\Models\UtilityBillOrder;

/**
 * Admin operational snapshot of paid Utility Bills between payment and the
 * provider's final answer. Read-only and index-backed: it only counts the
 * small set of non-terminal orders and reads hourly rates from the events
 * table's (kind, created_at) index, so it stays cheap on a large history.
 *
 * Capacity is derived from the CONFIGURED pay budget (the provider limit we
 * operate under), never assumed: XTRA4U cannot submit bills faster than this.
 */
class UtilityBillPipeline
{
    public function __construct(private ProviderRateBudget $budget) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $open = [FulfillmentStatus::QUEUED, FulfillmentStatus::SUBMITTING, FulfillmentStatus::PROVIDER_PENDING,
            FulfillmentStatus::PROVIDER_PROCESSING, FulfillmentStatus::ATTENTION];

        $counts = UtilityBillOrder::query()
            ->whereIn('fulfillment_status', $open)
            ->selectRaw('fulfillment_status, count(*) as n')
            ->groupBy('fulfillment_status')
            ->pluck('n', 'fulfillment_status')
            ->map(fn ($n) => (int) $n);

        $waiting = $counts->get(FulfillmentStatus::QUEUED, 0);
        $retryScheduled = $waiting > 0
            ? UtilityBillOrder::query()->where('fulfillment_status', FulfillmentStatus::QUEUED)->where('next_submit_at', '>', now())->count()
            : 0;

        $oldest = $waiting > 0
            ? UtilityBillOrder::query()->with('order:id,payment_completed_at')->where('fulfillment_status', FulfillmentStatus::QUEUED)->orderBy('id')->first()
            : null;
        $oldestSince = $oldest ? ($oldest->order?->payment_completed_at ?? $oldest->created_at) : null;
        $oldestMinutes = $oldestSince ? (int) floor($oldestSince->diffInSeconds(now(), true) / 60) : 0;

        $perMinute = $this->budget->limit('pay');
        $drainMinutes = $waiting > 0 ? (int) ceil($waiting / max(1, $perMinute)) : 0;
        $warnAfter = max(1, (int) config('utility_bills.backlog_warn_minutes', 10));

        $hourAgo = now()->subHour();
        $paidLastHour = UtilityBillEvent::query()->where('kind', 'payment_confirmed')->where('created_at', '>=', $hourAgo)->count();
        $submittedLastHour = UtilityBillEvent::query()->where('kind', 'provider_accepted')->where('created_at', '>=', $hourAgo)->count();

        $accumulating = $waiting > 0 && ($drainMinutes > $warnAfter || $oldestMinutes > $warnAfter);

        return [
            'awaiting_submission' => $waiting,
            'retry_scheduled' => $retryScheduled,
            'submitting' => $counts->get(FulfillmentStatus::SUBMITTING, 0),
            'with_provider' => $counts->get(FulfillmentStatus::PROVIDER_PENDING, 0) + $counts->get(FulfillmentStatus::PROVIDER_PROCESSING, 0),
            'attention' => $counts->get(FulfillmentStatus::ATTENTION, 0),
            'oldest_waiting_since' => $oldestSince,
            'oldest_waiting_minutes' => $oldestMinutes,
            'pay_per_minute' => $perMinute,
            'pay_per_hour' => $perMinute * 60,
            'provider_documented_pay_per_minute' => (int) config('utility_bills.provider_documented_rate.pay', 0),
            'pay_used_this_minute' => $this->budget->used('pay'),
            'drain_minutes' => $drainMinutes,
            'paid_last_hour' => $paidLastHour,
            'submitted_last_hour' => $submittedLastHour,
            'accumulating' => $accumulating,
            'state' => $waiting === 0 ? 'clear' : ($accumulating ? 'capacity_backlog' : 'flowing'),
        ];
    }
}
