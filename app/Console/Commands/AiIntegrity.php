<?php

namespace App\Console\Commands;

use App\Services\Ai\Exceptions\AiIntegrityException;
use App\Services\Ai\Integrity\IntegrityGuard;
use Illuminate\Console\Command;

/**
 * Operator tooling for the independent integrity anchor (M5.7.2). Nothing here ever releases the kill switch, resets or
 * lowers a budget, or contacts a provider. `status` and `verify` are read-only; `initialise` and `reconcile` are explicit
 * recovery decisions that each require a reason and, where trust is being extended, a dedicated confirmation flag.
 */
class AiIntegrity extends Command
{
    protected $signature = 'ai:integrity {action : status | verify | initialise | reconcile}
        {--reason= : Required for initialise and reconcile; stored with the change}
        {--accept-database-history : initialise: the existing ledger rows are trusted as the starting history}
        {--replace-bound-anchor : initialise: the database already belongs to an anchor that is LOST; start a new one}
        {--rebind-database : reconcile: bind this database to the anchor (it predates the binding or belongs to another anchor)}
        {--adopt-database-rows : reconcile: hand ledger rows the anchor never saw to the anchor (the anchor is older than the database)}
        {--json : Print the status as JSON}';

    protected $description = 'Independent budget-integrity anchor: status/verify (read-only), initialise and reconcile (explicit recovery decisions). Never releases the kill switch.';

    public function handle(IntegrityGuard $guard): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'status', 'verify' => $this->report($guard, $action === 'verify'),
            'initialise', 'initialize' => $this->initialise($guard),
            'reconcile' => $this->reconcile($guard),
            default => $this->invalid(),
        };
    }

    private function invalid(): int
    {
        $this->error('Action must be one of: status, verify, initialise, reconcile.');

        return self::INVALID;
    }

    private function report(IntegrityGuard $guard, bool $strict): int
    {
        $status = $guard->status();

        if ($this->option('json')) {
            $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('anchor configured: '.($status['configured'] ? 'yes ('.$status['driver'].')' : 'NO - live AI is blocked'));
            $this->line('anchor readable: '.(($status['anchor_readable'] ?? false) ? 'yes' : 'NO ('.($status['anchor_error'] ?? 'not configured').')'));

            if ($status['anchor_readable'] ?? false) {
                $this->line("anchor sequence: {$status['anchor_seq']} ({$status['anchor_records']} reservation record(s)); reconciliation: {$status['reconciliation']}");
                $this->line("anchor kill switch: {$status['kill_state']} (generation {$status['kill_generation']})");
            }

            $this->line('database binding: '.($status['database_bound_to'] ?? 'none').' | database sequence: '.($status['database_seq'] ?? '?').' | ledger rows: '.($status['database_records'] ?? '?'));

            foreach (['readiness' => 'NOT READY', 'problems' => 'MISMATCH'] as $key => $label) {
                foreach ($status[$key] as $p) {
                    $this->warn("{$label}: {$p}");
                }
            }
        }

        $clean = $status['readiness'] === [] && $status['problems'] === [];
        if (! $this->option('json')) {
            $clean ? $this->info('The database agrees with the anchor.') : $this->warn('AI stays blocked until the problems above are resolved (docs/ai/06 section 19).');
        }

        return $clean || ! $strict ? self::SUCCESS : self::FAILURE;
    }

    private function initialise(IntegrityGuard $guard): int
    {
        $reason = $this->reason();
        if ($reason === null) {
            return self::INVALID;
        }

        try {
            $r = $guard->initialise($reason, (bool) $this->option('accept-database-history'), (bool) $this->option('replace-bound-anchor'));
        } catch (AiIntegrityException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Anchor {$r['anchor_id']} created from {$r['records']} existing reservation(s) (sequence {$r['seq']}); the database is bound to it.");
        $this->warn('The kill switch is ENGAGED. Run `php artisan ai:preflight --live`, then release it deliberately with `php artisan ai:kill-switch release`.');

        return self::SUCCESS;
    }

    private function reconcile(IntegrityGuard $guard): int
    {
        $reason = $this->reason();
        if ($reason === null) {
            return self::INVALID;
        }

        try {
            $r = $guard->reconcile($reason, (bool) $this->option('rebind-database'), (bool) $this->option('adopt-database-rows'));
        } catch (AiIntegrityException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("restored from the anchor: {$r['restored']} | charges raised: {$r['raised']} | adopted by the anchor: {$r['adopted']} | counter rows rebuilt from the ledger: {$r['counters_rebuilt']}");

        if ($r['remaining_problems'] !== []) {
            foreach ($r['remaining_problems'] as $p) {
                $this->warn($p);
            }
            $this->error('The accounting still does not agree with the anchor. AI stays blocked and the reconciliation requirement stays in place.');

            return self::FAILURE;
        }

        $this->info('The accounting agrees with the anchor and the reconciliation requirement is cleared.');
        $this->warn('The kill switch was NOT released. Reconcile the day with the provider\'s own usage first, then run `php artisan ai:kill-switch release`.');

        return self::SUCCESS;
    }

    private function reason(): ?string
    {
        $reason = $this->option('reason');

        if (! is_string($reason) || strlen(trim($reason)) < 5) {
            $this->error('--reason is required (at least 5 characters): say who decided this and why.');

            return null;
        }

        return trim($reason);
    }
}
