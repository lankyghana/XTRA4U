<?php

namespace App\Console\Commands;

use App\Services\UtilityBills\UtilityBillSweeper;
use Illuminate\Console\Command;

class UtilityBillsSync extends Command
{
    protected $signature = 'utility-bills:sync {--submit-limit= : Defaults to the pay budget per minute} {--poll-limit=20}';

    protected $description = 'Recover queued/stuck Utility Bill submissions and sync in-flight provider order statuses';

    public function handle(UtilityBillSweeper $sweeper): int
    {
        $submitLimit = $this->option('submit-limit');
        $r = $sweeper->run($submitLimit !== null ? (int) $submitLimit : null, (int) $this->option('poll-limit'));

        $this->info("dispatched={$r['dispatched']} requeued={$r['requeued']} polled={$r['polled']} alerted={$r['alerted']}");

        return self::SUCCESS;
    }
}
