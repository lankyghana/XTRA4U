<?php

namespace App\Console\Commands;

use App\Jobs\ProcessVendorWithdrawalPayout;
use App\Models\VendorWithdrawal;
use Illuminate\Console\Command;

class RetryStuckWithdrawals extends Command
{
    protected $signature = 'withdrawals:retry-stuck
                            {--minutes=10 : Only retry withdrawals last attempted more than N minutes ago (or never attempted)}
                            {--limit=200 : Max number of withdrawals to dispatch}
                            {--dry-run : Show what would be dispatched without dispatching jobs}';

    protected $description = 'Re-dispatch automatic payout jobs for withdrawals stuck in processing';

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $cutoff = now()->subMinutes($minutes);

        $query = VendorWithdrawal::query()
            ->where('status', VendorWithdrawal::STATUS_PROCESSING)
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('payout_attempted_at')
                  ->orWhere('payout_attempted_at', '<=', $cutoff);
            })
            ->orderBy('id');

        $count = (clone $query)->limit($limit)->count();

        $this->info("Found {$count} processing withdrawals eligible for retry.");

        if ($dryRun) {
            $this->warn('DRY RUN MODE - no jobs will be dispatched');
        }

        $withdrawals = $query->limit($limit)->get(['id', 'reference', 'vendor_id', 'amount', 'payout_attempted_at']);

        foreach ($withdrawals as $withdrawal) {
            $this->line(sprintf(
                'Retry withdrawal #%d (%s) vendor=%d amount=%.2f last_attempt=%s',
                $withdrawal->id,
                $withdrawal->reference,
                $withdrawal->vendor_id,
                (float) $withdrawal->amount,
                optional($withdrawal->payout_attempted_at)->toDateTimeString() ?? 'never'
            ));

            if (! $dryRun) {
                ProcessVendorWithdrawalPayout::dispatch($withdrawal->id);
            }
        }

        $this->info('Done.');

        return Command::SUCCESS;
    }
}
