<?php

namespace App\Console\Commands;

use App\Services\DeveloperApi\Sandbox\SandboxCleanup;
use Illuminate\Console\Command;

/**
 * Sandbox retention. Does nothing unless a retention is configured (DEVELOPER_API_SANDBOX_RETENTION_DAYS)
 * or --days is given, and never deletes data younger than developer_api.sandbox.min_retention_days.
 * Production orders, transactions, keys and audit records are out of its reach by construction.
 */
class DeveloperSandboxPrune extends Command
{
    protected $signature = 'developer-sandbox:prune {--days= : Delete sandbox data idle for at least this many days (floor enforced)} {--dry-run : Report what would be deleted}';

    protected $description = 'Delete old SANDBOX orders, payments, events and quotes (never production data)';

    public function handle(SandboxCleanup $cleanup): int
    {
        $days = $this->option('days');
        if ($days !== null && ! ctype_digit((string) $days)) {
            $this->error('--days must be a whole number.');

            return self::INVALID;
        }

        $requested = $days !== null ? (int) $days : null;
        $effective = $cleanup->effectiveDays($requested);

        if ($effective === null) {
            $this->info('Sandbox cleanup is disabled (no retention configured). Nothing deleted.');

            return self::SUCCESS;
        }

        if ($requested !== null && $requested < $effective) {
            $this->warn("Requested {$requested} days is below the safety floor; using {$effective} days.");
        }

        $r = $cleanup->run($requested, (bool) $this->option('dry-run'));

        $this->info(sprintf(
            '%s sandbox data idle since %s: orders=%d events=%d deliveries=%d quotes=%d',
            $r['dry_run'] ? 'Would delete' : 'Deleted', $r['cutoff'], $r['orders'], $r['events'], $r['deliveries'], $r['quotes']
        ));

        return self::SUCCESS;
    }
}
