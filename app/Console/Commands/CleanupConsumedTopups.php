<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\WalletTopup;
use Illuminate\Support\Facades\DB;

class CleanupConsumedTopups extends Command
{
    protected $signature = 'wallet:cleanup-consumed {--days=30}';
    protected $description = 'Delete fully-consumed wallet topups older than N days (default 30)';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days)->toDateTimeString();

        $this->info("Cleaning up fully-consumed topups older than {$days} days (before {$cutoff})");

        $query = WalletTopup::whereColumn('amount', '<=', 'consumed')
            ->where('updated_at', '<', $cutoff);

        $count = $query->count();

        // Delete in chunks to avoid long-running transactions
        $deleted = 0;
        $query->chunkById(200, function ($rows) use (&$deleted) {
            $ids = $rows->pluck('id')->all();
            WalletTopup::whereIn('id', $ids)->delete();
            $deleted += count($ids);
        });

        $this->info("Deleted {$deleted} consumed topup records.");

        return 0;
    }
}
