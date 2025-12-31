<?php

namespace App\Console\Commands;

use App\Models\Vendor;
use App\Models\VendorWithdrawal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CancelStaleWithdrawals extends Command
{
    protected $signature = 'withdrawals:cancel-stale
                            {--minutes=5 : Cancel withdrawals that have been processing longer than N minutes}
                            {--limit=200 : Max number of withdrawals to cancel}
                            {--dry-run : Show what would be cancelled without making changes}';

    protected $description = 'Cancel vendor withdrawals stuck in processing for too long (refunds wallet once)';

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $cutoff = now()->subMinutes($minutes);

        $query = VendorWithdrawal::query()
            ->where('status', VendorWithdrawal::STATUS_PROCESSING)
            // Only cancel if we have no payout identifiers (reduces risk of cancelling an in-flight payout).
            ->whereNull('payout_reference')
            ->whereNull('payout_transaction_id')
            ->where(function ($q) use ($cutoff) {
                // Prefer payout_attempted_at when set, otherwise fall back to created_at.
                $q->where(function ($q2) use ($cutoff) {
                    $q2->whereNotNull('payout_attempted_at')
                        ->where('payout_attempted_at', '<=', $cutoff);
                })->orWhere(function ($q2) use ($cutoff) {
                    $q2->whereNull('payout_attempted_at')
                        ->where('created_at', '<=', $cutoff);
                });
            })
            ->orderBy('id');

        $withdrawals = $query->limit($limit)->get(['id', 'reference', 'vendor_id', 'amount', 'payout_attempted_at', 'created_at']);

        $this->info('Found ' . $withdrawals->count() . ' stale processing withdrawals eligible for cancellation.');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - no withdrawals will be modified');
        }

        foreach ($withdrawals as $withdrawal) {
            $age = $withdrawal->payout_attempted_at ?? $withdrawal->created_at;
            $this->line(sprintf(
                'Cancel withdrawal #%d (%s) vendor=%d amount=%.2f since=%s',
                $withdrawal->id,
                (string) $withdrawal->reference,
                (int) $withdrawal->vendor_id,
                (float) $withdrawal->amount,
                optional($age)->toDateTimeString() ?? 'unknown'
            ));

            if ($dryRun) {
                continue;
            }

            $didCancel = false;

            DB::transaction(function () use (&$didCancel, $withdrawal, $cutoff, $minutes) {
                $locked = VendorWithdrawal::whereKey($withdrawal->id)->lockForUpdate()->first();

                if (! $locked) {
                    return;
                }

                if ($locked->status !== VendorWithdrawal::STATUS_PROCESSING) {
                    return;
                }

                if ($locked->payout_reference !== null || $locked->payout_transaction_id !== null) {
                    return;
                }

                $age = $locked->payout_attempted_at ?? $locked->created_at;
                if (! $age || $age->gt($cutoff)) {
                    return;
                }

                $locked->update([
                    'status' => VendorWithdrawal::STATUS_FAILED,
                    'payout_status' => 'failed',
                    'error_message' => 'Auto-cancelled after ' . $minutes . ' minutes in processing',
                ]);

                // Refund wallet balance exactly once.
                if ($locked->refunded_at === null) {
                    $vendor = Vendor::whereKey($locked->vendor_id)->lockForUpdate()->first();
                    if ($vendor) {
                        $vendor->increment('wallet_balance', $locked->amount);
                    }

                    $locked->update(['refunded_at' => now()]);
                }

                $didCancel = true;
            });

            if ($didCancel) {
                Log::warning('Vendor withdrawal auto-cancelled due to processing timeout', [
                    'withdrawal_id' => $withdrawal->id,
                    'vendor_id' => $withdrawal->vendor_id,
                    'amount' => $withdrawal->amount,
                    'minutes' => $minutes,
                ]);
            }
        }

        $this->info('Done.');

        return Command::SUCCESS;
    }
}
