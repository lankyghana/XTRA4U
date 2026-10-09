<?php

namespace App\Services\UtilityBills;

use App\Jobs\SyncUtilityBillStatus;
use App\Models\UtilityBillOrder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Scheduler-driven recovery and status synchronisation. Independent of any
 * customer browser. Per-run limits keep normal traffic inside the provider's
 * rate limits (pay 6/min, status 30/min); the provider client enforces its own
 * budget on top of these.
 *
 *  - is the ONLY re-dispatcher of submissions: paid orders still queued (budget
 *    deferral, transient retry, lost job) or whose worker died (stale submitting
 *    claim), oldest order first, at most the pay budget per run;
 *  - re-queues attention orders whose cause is transient (provider wallet,
 *    provider disabled, timeouts), at most every 15 minutes;
 *  - queues status checks for in-flight provider orders with backoff (the HTTP
 *    happens in SyncUtilityBillStatus on a worker, never inside schedule:run);
 *    terminal orders are never polled;
 *  - alerts an admin once for any order PAID longer than
 *    status_attention_after_minutes that still has not finished, whatever the
 *    provider has answered (never auto-failed, never auto-refunded).
 */
class UtilityBillSweeper
{
    public function __construct(private UtilityBillFulfillmentService $fulfillment) {}

    /** Marks an order as having a sweeper-dispatched submit job queued or running. */
    public static function dispatchKey(int $id): string
    {
        return 'utility_bills.dispatched.'.$id;
    }

    /** Called when that job finishes, so the next run may dispatch the order again if still due. */
    public static function releaseDispatch(int $id): void
    {
        Cache::forget(self::dispatchKey($id));
    }

    /**
     * @param  ?int  $submitLimit  defaults to the pay budget per minute (the sweep runs every minute)
     * @return array{dispatched:int, requeued:int, polled:int, alerted:int}
     */
    public function run(?int $submitLimit = null, int $pollLimit = 20): array
    {
        $submitLimit ??= max(1, (int) config('utility_bills.rate.pay_per_minute', 5));

        $requeued = $this->requeueTransientAttention($submitLimit);
        $dispatched = $this->dispatchDueSubmissions($submitLimit);
        $polled = $this->pollDueOrders($pollLimit);
        $alerted = $this->alertStuckOrders();

        return ['dispatched' => $dispatched, 'requeued' => $requeued, 'polled' => $polled, 'alerted' => $alerted];
    }

    private function alertStuckOrders(int $limit = 20): int
    {
        $cutoff = now()->subMinutes(max(1, (int) config('utility_bills.status_attention_after_minutes')));

        $ids = UtilityBillOrder::query()
            ->whereIn('fulfillment_status', FulfillmentStatus::STUCK_ALERTABLE)
            ->whereNull('stuck_alerted_at')
            ->where('created_at', '<=', $cutoff)   // cheap pre-filter: an order is paid after it is created
            ->whereHas('order', fn ($o) => $o
                ->whereIn('payment_status', ['paid', 'completed'])
                ->where(fn ($q) => $q->whereNull('payment_completed_at')->orWhere('payment_completed_at', '<=', $cutoff)))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        return $ids->filter(fn ($id) => $this->fulfillment->raiseStuckAlert((int) $id))->count();
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

        $dispatched = 0;

        foreach ($ids as $id) {
            // One queued job per order: the key is released when the job finishes, and expires after
            // ~2 minutes if workers are down, so the queue never fills with duplicates. An older order
            // whose job is still pending keeps its slot, which keeps submissions oldest-first.
            if (! Cache::add(self::dispatchKey((int) $id), 1, 120)) {
                continue;
            }

            $this->fulfillment->dispatchSubmit((int) $id, fromSweeper: true);
            $dispatched++;
        }

        return $dispatched;
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

        $dispatched = 0;

        // The checks themselves run on the queue: provider latency must never hold
        // schedule:run (and every other scheduled task) hostage. The per-run limit
        // stays under the status budget, and each order is queued at most once per
        // couple of minutes so a stopped worker cannot pile up duplicates.
        foreach ($ids as $id) {
            if (! Cache::add('utility_bills.status_dispatched.'.$id, 1, 120)) {
                continue;
            }

            SyncUtilityBillStatus::dispatch((int) $id);
            $dispatched++;
        }

        return $dispatched;
    }
}
