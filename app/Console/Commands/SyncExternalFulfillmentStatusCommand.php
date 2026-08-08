<?php

namespace App\Console\Commands;

use App\Jobs\SyncExternalFulfillmentStatuses;
use Illuminate\Console\Command;

class SyncExternalFulfillmentStatusCommand extends Command
{
    protected $signature = 'xtra4u:sync-external-fulfillment
                            {--vendor= : Restrict the sync to a single vendor id}
                            {--sync : Run inline instead of dispatching to the queue}';

    protected $description = 'Poll external fulfillment providers for delivery status and complete delivered orders';

    public function handle(): int
    {
        $vendorOption = $this->option('vendor');
        $vendorId = is_numeric($vendorOption) ? (int) $vendorOption : null;

        if (! config('external_fulfillment.polling.enabled', true)) {
            $this->warn('External fulfillment polling is disabled (external_fulfillment.polling.enabled).');

            return self::SUCCESS;
        }

        if ($this->option('sync')) {
            SyncExternalFulfillmentStatuses::dispatchSync($vendorId);
            $this->info('External fulfillment status sync completed.');

            return self::SUCCESS;
        }

        SyncExternalFulfillmentStatuses::dispatch($vendorId);
        $this->info('External fulfillment status sync queued.');

        return self::SUCCESS;
    }
}
