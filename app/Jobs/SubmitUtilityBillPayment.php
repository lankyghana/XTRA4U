<?php

namespace App\Jobs;

use App\Services\UtilityBills\UtilityBillFulfillmentService;
use App\Services\UtilityBills\UtilityBillSweeper;
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
 * provider reference). Running this job twice, concurrently or repeatedly, is
 * safe: the second run finds the order claimed/submitted and does nothing. If a
 * worker dies mid-flight the scheduler sweep re-dispatches after the claim goes
 * stale, and the retry reuses the same persisted provider reference.
 *
 * Only the first attempt is dispatched directly (payment confirmed / admin
 * retry), and it yields to older orders still waiting. Every later attempt
 * comes from UtilityBillSweeper ($fromSweeper), oldest order first; a job never
 * re-dispatches itself.
 */
class SubmitUtilityBillPayment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $utilityBillOrderId, public bool $fromSweeper = false) {}

    public function handle(UtilityBillFulfillmentService $fulfillment): void
    {
        try {
            $fulfillment->submit($this->utilityBillOrderId, yieldToBacklog: ! $this->fromSweeper);
        } finally {
            // This sweeper dispatch is done: the next run may dispatch the order again if still due.
            if ($this->fromSweeper) {
                UtilityBillSweeper::releaseDispatch($this->utilityBillOrderId);
            }
        }
    }
}
