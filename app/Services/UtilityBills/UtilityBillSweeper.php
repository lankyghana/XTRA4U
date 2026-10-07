<?php

namespace App\Services\UtilityBills;

use App\Models\UtilityBillOrder;
use Illuminate\Support\Facades\DB;

/**
 * Scheduler-driven recovery and status synchronisation. Independent of any
 * customer browser. Per-run limits keep normal traffic inside the provider's
 * rate limits (pay 6/min, status 30/min); the provider client enforces its own
 * budget on top of these.
 *
 *  - re-dispatches paid orders still queued (lost job) or whose worker died
 *    (stale submitting claim);
 *  - re-queues attention orders whose cause is transient (provider wallet,
 *    provider disabled, timeouts), at most every 15 minutes;
 *  - polls in-flight provider orders with backoff; terminal orders are never polled.
 */
class UtilityBillSweeper
{
    public function __construct(private UtilityBillFulfillmentService $fulfillment) {}

    /** @return array{dispatched:int, requeued:int, polled:int} */
    public function run(int $submitLimit = 5, int $pollLimit = 20): array
    {
        $requeued = $this->requeueTransientAttention($submitLimit);
        $dispatched = $this->dispatchDueSubmissions($submitLimit);
        $polled = $this->pollDueOrders($pollLimit);

        return ['dispatched' => $dispatched, 'requeued' => $requeued, 'polled' => $polled];
    }

    private function dispatchDueSubmissions(int $limit): int
    {
        $stale = now()->subSeconds((int) config('utility_bills.claim_stale_seconds'));

        $ids = UtilityBillOrder::query()
            ->whereNull('provider_order_reference')
            ->where(function ($q) use ($stale) {
                $q->where(function ($q) {
                    $q->where('fulfillment_status', FulfillmentStatus::QUEUED)
                        ->where(fn ($q) => $q->whereNull('next_submit_at')->orWhere('next_submit_at', '<=', now()));
                })->orWhere(function ($q) use ($stale) {
                    $q->where('fulfillment_status', FulfillmentStatus::SUBMITTING)->where('claimed_at', '<', $stale);
                });
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            $this->fulfillment->dispatchSubmit((int) $id);
        }

        return $ids->count();
    }

    private function requeueTransientAttention(int $limit): int
    {
        $ids = UtilityBillOrder::query()
            ->where('fulfillment_status', FulfillmentStatus::ATTENTION)
            ->whereNull('provider_order_reference')
            ->whereIn('last_error_code', UtilityBillFulfillmentService::AUTO_RETRY_CODES)
            ->whereNotNull('next_submit_at')
            ->where('next_submit_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $count = 0;

        foreach ($ids as $id) {
            $moved = DB::transaction(function () use ($id) {
                $u = UtilityBillOrder::query()->whereKey($id)->lockForUpdate()->first();

                if (! $u || $u->fulfillment_status !== FulfillmentStatus::ATTENTION || $u->provider_order_reference !== null) {
                    return false;
                }

                $u->forceFill(['fulfillment_status' => FulfillmentStatus::QUEUED, 'next_submit_at' => now()])->save();
                $u->events()->create([
                    'kind' => 'auto_requeue',
                    'from_status' => FulfillmentStatus::ATTENTION,
                    'to_status' => FulfillmentStatus::QUEUED,
                    'detail' => 'same provider reference '.($u->provider_request_reference ?? '(none yet)'),
                    'actor' => 'system',
                ]);

                return true;
            });

            if ($moved) {
                $count++;
            }
        }

        return $count;
    }

    private function pollDueOrders(int $limit): int
    {
        $ids = UtilityBillOrder::query()
            ->whereIn('fulfillment_status', FulfillmentStatus::POLLABLE)
            ->whereNotNull('provider_order_reference')
            ->where(fn ($q) => $q->whereNull('next_status_check_at')->orWhere('next_status_check_at', '<=', now()))
            ->orderBy('next_status_check_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            if ($this->fulfillment->syncStatus((int) $id) === 'rate_limited') {
                break; // out of budget: stop for this run
            }
        }

        return $ids->count();
    }
}
