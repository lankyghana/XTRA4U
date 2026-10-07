<?php

namespace App\Jobs;

use App\Services\UtilityBills\UtilityBillFulfillmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Submits a PAID Utility Bill order to KiNG FLEXY.
 *
 * Deliberately not ShouldBeUnique: exactly-once is enforced by the database
 * claim inside UtilityBillFulfillmentService (row lock + claim token + stable
 * provider reference), which also lets a running job schedule its own delayed
 * retry. Running this job twice, concurrently or repeatedly, is safe: the
 * second run finds the order claimed/submitted and does nothing. If a worker
 * dies mid-flight the scheduler sweep re-dispatches after the claim goes stale,
 * and the retry reuses the same persisted provider reference.
 */
class SubmitUtilityBillPayment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $utilityBillOrderId) {}

    public function handle(UtilityBillFulfillmentService $fulfillment): void
    {
        $fulfillment->submit($this->utilityBillOrderId);
    }
}
