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
                            {--dry-run : Show what would be cancelled without making changes}
                            {--explain : In dry-run mode, explain why processing withdrawals are skipped}
                            {--all : Ignore age cutoff and target all processing withdrawals (requires --force)}
                            {--include-referenced : Also target withdrawals that already have payout_reference/transaction_id (requires --force)}
                            {--force : Required for unsafe modes like --all/--include-referenced}';

    protected $description = 'Cancel vendor withdrawals stuck in processing for too long (refunds wallet once)';

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $explain = (bool) $this->option('explain');
        $all = (bool) $this->option('all');
        $includeReferenced = (bool) $this->option('include-referenced');
        $force = (bool) $this->option('force');

        if (($all || $includeReferenced) && ! $force) {
            $this->error('Refusing to run with --all or --include-referenced without --force (risk of cancelling in-flight payouts).');
            return Command::FAILURE;
        }

        $cutoff = now()->subMinutes($minutes);

        $targetStatuses = [VendorWithdrawal::STATUS_PROCESSING, 'pending'];

        $query = VendorWithdrawal::query()
            ->whereIn('status', $targetStatuses)
            ->when(! $includeReferenced, function ($q) {
                // Only cancel if we have no payout identifiers (reduces risk of cancelling an in-flight payout).
                $q->whereNull('payout_reference')
                    ->whereNull('payout_transaction_id');
            })
            ->when(! $all, function ($q) use ($cutoff) {
                $q->where(function ($q2) use ($cutoff) {
                    // Prefer payout_attempted_at when set, otherwise fall back to created_at.
                    $q2->where(function ($q3) use ($cutoff) {
                        $q3->whereNotNull('payout_attempted_at')
                            ->where('payout_attempted_at', '<=', $cutoff);
                    })->orWhere(function ($q3) use ($cutoff) {
                        $q3->whereNull('payout_attempted_at')
                            ->where('created_at', '<=', $cutoff);
                    });
                });
            })
            ->orderBy('id');

        $withdrawals = $query->limit($limit)->get(['id', 'reference', 'vendor_id', 'amount', 'payout_attempted_at', 'created_at']);

        $this->info('Found ' . $withdrawals->count() . ' stale processing withdrawals eligible for cancellation.');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - no withdrawals will be modified');
        }

        if ($dryRun && $explain) {
            $processing = VendorWithdrawal::query()
                ->whereIn('status', $targetStatuses)
                ->orderBy('id')
                ->limit($limit)
                ->get(['id', 'reference', 'vendor_id', 'amount', 'payout_attempted_at', 'created_at', 'payout_reference', 'payout_transaction_id']);

            if ($processing->isNotEmpty()) {
                $this->newLine();
                $this->info('Explain (first ' . $processing->count() . ' processing withdrawals):');
            }

            foreach ($processing as $w) {
                $age = $w->payout_attempted_at ?? $w->created_at;
                $reasons = [];

                if (! $includeReferenced && ($w->payout_reference !== null || $w->payout_transaction_id !== null)) {
                    $reasons[] = 'has payout reference/txn id';
                }

                if (! $all) {
                    if (! $age) {
                        $reasons[] = 'missing timestamps';
                    } elseif ($age->gt($cutoff)) {
                        $reasons[] = 'not older than cutoff (' . $minutes . 'm)';
                    }
                }

                if (empty($reasons)) {
                    $reasons[] = 'ELIGIBLE';
                }

                $this->line(sprintf(
                    '#%d (%s) vendor=%d amount=%.2f since=%s => %s',
                    $w->id,
                    (string) $w->reference,
                    (int) $w->vendor_id,
                    (float) $w->amount,
                    optional($age)->toDateTimeString() ?? 'unknown',
                    implode(', ', $reasons)
                ));
            }
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

            DB::transaction(function () use (&$didCancel, $withdrawal, $cutoff, $minutes, $all, $includeReferenced) {
                $locked = VendorWithdrawal::whereKey($withdrawal->id)->lockForUpdate()->first();

                if (! $locked) {
                    return;
                }

                if (!in_array($locked->status, [VendorWithdrawal::STATUS_PROCESSING, 'pending'], true)) {
                    return;
                }

                if (! $includeReferenced && ($locked->payout_reference !== null || $locked->payout_transaction_id !== null)) {
                    return;
                }

                if (! $all) {
                    $age = $locked->payout_attempted_at ?? $locked->created_at;
                    if (! $age || $age->gt($cutoff)) {
                        return;
                    }
                }

                $locked->update([
                    'status' => VendorWithdrawal::STATUS_FAILED,
                    'payout_status' => 'failed',
                    'error_message' => ($all ? 'Force-cancelled while processing' : ('Auto-cancelled after ' . $minutes . ' minutes in processing')),
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
