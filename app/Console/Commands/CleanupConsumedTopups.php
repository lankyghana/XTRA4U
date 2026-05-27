<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\WalletTopup;
use Illuminate\Support\Facades\DB;

class CleanupConsumedTopups extends Command
{
    protected $signature = 'wallet:cleanup-topups {--days=30} {--archive}';
    protected $description = 'Archive or expire old completed topups (default: consumed; --archive to move to historical table)';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $shouldArchive = $this->option('archive');
        $cutoff = now()->subDays($days)->toDateTimeString();

        // Cleanup fully-consumed completed topups
        $this->info("Cleaning up fully-consumed topups older than {$days} days (before {$cutoff})");

        $query = WalletTopup::where('status', 'completed')
            ->whereColumn('amount', '<=', 'consumed')
            ->where('updated_at', '<', $cutoff);

        $count = $query->count();

        if ($shouldArchive) {
            // Archive to historical table for audit trail
            $this->info("Archiving {$count} consumed topup records...");
            $query->chunkById(200, function ($rows) {
                // Note: requires WalletTopupArchive model/table if you want full archival
                // For now, just soft-mark as archived by status
                $ids = $rows->pluck('id')->all();
                WalletTopup::whereIn('id', $ids)->update(['status' => 'archived']);
            });
            $this->info("Archived {$count} records.");
        } else {
            // Mark as expired (preserves record for reconciliation, removes from active queries)
            $this->info("Marking {$count} consumed topup records as expired...");
            $updated = $query->update(['status' => 'archived']);
            $this->info("Marked {$updated} records as archived.");
        }

        // Cleanup abandoned initiated topups (> 24 hours old, never completed)
        $this->info("Cleaning up abandoned 'initiated' topups older than 1 day...");
        $initiatedCutoff = now()->subDay()->toDateTimeString();

        $initiatedQuery = WalletTopup::where('status', 'initiated')
            ->where('created_at', '<', $initiatedCutoff);

        $initiatedCount = $initiatedQuery->count();
        $this->info("Found {$initiatedCount} abandoned initiated topups");

        if ($initiatedCount > 0) {
            $initiatedQuery->chunkById(200, function ($rows) {
                $ids = $rows->pluck('id')->all();
                WalletTopup::whereIn('id', $ids)->update(['status' => 'expired']);
            });
            $this->info("Marked {$initiatedCount} abandoned topups as expired.");
        }

        $this->info('Cleanup complete.');
        return 0;
    }
}
