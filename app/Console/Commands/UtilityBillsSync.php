<?php

namespace App\Console\Commands;

use App\Services\UtilityBills\UtilityBillSweeper;
use Illuminate\Console\Command;

class UtilityBillsSync extends Command
{
    protected $signature = 'utility-bills:sync {--submit-limit=5} {--poll-limit=20}';

    protected $description = 'Recover queued/stuck Utility Bill submissions and sync in-flight provider order statuses';

    public function handle(UtilityBillSweeper $sweeper): int
    {
        $r = $sweeper->run((int) $this->option('submit-limit'), (int) $this->option('poll-limit'));

        $this->info("dispatched={$r['dispatched']} requeued={$r['requeued']} polled={$r['polled']}");

        return self::SUCCESS;
    }
}
