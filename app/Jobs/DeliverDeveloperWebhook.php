<?php

namespace App\Jobs;

use App\Services\DeveloperApi\Webhooks\WebhookDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * One delivery attempt. Retries are scheduled by the delivery row itself
 * (next_attempt_at + the sweeper), not by queue re-attempts, so $tries is 1 and a
 * crashed job is recovered by the stale-claim rule rather than blind re-runs.
 */
class DeliverDeveloperWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $deliveryId) {}

    public function handle(WebhookDispatcher $dispatcher): void
    {
        $dispatcher->deliver($this->deliveryId);
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('Developer webhook job crashed', ['delivery_id' => $this->deliveryId, 'error' => get_class($e)]);
    }
}
