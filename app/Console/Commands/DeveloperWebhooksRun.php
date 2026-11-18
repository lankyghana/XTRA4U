<?php

namespace App\Console\Commands;

use App\Services\DeveloperApi\VendorApiAccess;
use App\Services\DeveloperApi\Webhooks\OrderEventDetector;
use App\Services\DeveloperApi\Webhooks\WebhookDispatcher;
use App\Services\DeveloperApi\Webhooks\WebhookFanout;
use Illuminate\Console\Command;

/**
 * The webhook pipeline tick: detect -> persist events -> fan out -> dispatch due deliveries.
 * Every stage is idempotent and independently recoverable, so a crash between stages
 * (or running this concurrently) can delay but never lose or duplicate an event.
 */
class DeveloperWebhooksRun extends Command
{
    protected $signature = 'developer-webhooks:run';

    protected $description = 'Detect developer API order events, fan them out to webhooks and dispatch due deliveries';

    public function handle(OrderEventDetector $detector, WebhookFanout $fanout, WebhookDispatcher $dispatcher, VendorApiAccess $access): int
    {
        // First: make sure no vendor that lost approval (by any route) still holds live keys/webhooks.
        $withdrawn = $access->reconcile();

        $events = $detector->sweep();
        $deliveries = $fanout->run();
        $queued = $dispatcher->dispatchDue();

        $this->info("events={$events} deliveries_created={$deliveries} dispatched={$queued} vendors_withdrawn={$withdrawn}");

        return self::SUCCESS;
    }
}
