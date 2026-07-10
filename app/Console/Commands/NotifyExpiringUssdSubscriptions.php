<?php

namespace App\Console\Commands;

use App\Services\Ussd\UssdSubscriptionService;
use Illuminate\Console\Command;

class NotifyExpiringUssdSubscriptions extends Command
{
    protected $signature = 'xtra4u:notify-expiring-ussd-subscriptions {--days=3}';

    protected $description = 'Warn vendors whose USSD subscription expires within the given number of days';

    public function handle(UssdSubscriptionService $subscriptions): int
    {
        $days = max(1, (int) $this->option('days'));

        $count = $subscriptions->notifyExpiringSoon($days);

        $this->info("Warned {$count} ".str('vendor')->plural($count)." about subscriptions expiring within {$days} days.");

        return self::SUCCESS;
    }
}
