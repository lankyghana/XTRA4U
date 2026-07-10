<?php

namespace App\Console\Commands;

use App\Services\Ussd\UssdSubscriptionService;
use Illuminate\Console\Command;

class ExpireUssdSubscriptions extends Command
{
    protected $signature = 'xtra4u:expire-ussd-subscriptions';

    protected $description = 'Expire past-due USSD subscriptions, releasing their code and vendor slot';

    public function handle(UssdSubscriptionService $subscriptions): int
    {
        // expireDue() is idempotent: a subscription that no longer occupies its
        // vendor's slot is skipped, so overlapping runs cannot double-expire.
        $count = $subscriptions->expireDue();

        $this->info("Expired {$count} USSD ".str('subscription')->plural($count).'.');

        return self::SUCCESS;
    }
}
