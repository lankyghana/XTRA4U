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
 * Polls KiNG FLEXY for one in-flight Utility Bill order.
 *
 * Runs on the queue (dispatched by UtilityBillSweeper) so provider latency never
 * blocks `schedule:run` and the other scheduled tasks. Safe to run repeatedly or
 * concurrently: the status is applied under a row lock, terminal states never
 * regress, and the provider client enforces the shared status budget.
 */
class SyncUtilityBillStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $utilityBillOrderId) {}

    public function handle(UtilityBillFulfillmentService $fulfillment): void
    {
        try {
            $fulfillment->syncStatus($this->utilityBillOrderId);
        } finally {
            UtilityBillSweeper::releaseStatusDispatch($this->utilityBillOrderId);
        }
    }
}
