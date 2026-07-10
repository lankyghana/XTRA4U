<?php

namespace App\Console\Commands;

use App\Models\UssdLog;
use App\Models\UssdSession;
use Illuminate\Console\Command;

class PruneUssdSessions extends Command
{
    protected $signature = 'xtra4u:prune-ussd-sessions {--days=7} {--log-days=90}';

    protected $description = 'Close abandoned USSD sessions and prune old session rows and interaction logs';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $logDays = max(1, (int) $this->option('log-days'));

        // Sessions the caller walked away from: never reached an END response
        // and idle far beyond any plausible timeout.
        $closed = UssdSession::where('status', UssdSession::STATUS_ACTIVE)
            ->where('updated_at', '<', now()->subHours(1))
            ->update([
                'status' => UssdSession::STATUS_EXPIRED,
                'ended_at' => now(),
            ]);

        $this->info("Closed {$closed} abandoned ".str('session')->plural($closed).'.');

        // Retention exists only to defeat session-id replay. A gateway session
        // id is worthless once the network has recycled it, so a week is ample
        // — but pruning sooner would reopen the replay window this table closes.
        $cutoff = now()->subDays($days);
        $pruned = 0;

        UssdSession::whereIn('status', [UssdSession::STATUS_ENDED, UssdSession::STATUS_EXPIRED])
            ->where('updated_at', '<', $cutoff)
            ->select('id')
            ->chunkById(500, function ($rows) use (&$pruned) {
                $pruned += UssdSession::whereIn('id', $rows->pluck('id'))->delete();
            });

        $this->info("Pruned {$pruned} closed ".str('session')->plural($pruned).'.');

        // Interaction logs carry customer phone numbers; keep them only as long
        // as they are useful for dispute resolution.
        $logsPruned = UssdLog::where('created_at', '<', now()->subDays($logDays))->delete();

        $this->info("Pruned {$logsPruned} USSD interaction ".str('log')->plural($logsPruned).'.');

        return self::SUCCESS;
    }
}
