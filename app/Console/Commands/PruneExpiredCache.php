<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deletes expired rows from the database cache store.
 *
 * Laravel's database store removes an expired row only when the same key is
 * read again, so one-off keys are never cleaned up: Utility Bill lookup tokens
 * and lookup results (which hold customer account names, meters and phone
 * numbers), per-order dispatch markers, rate-limiter windows. Without this the
 * table grows forever and customer data outlives its 2-15 minute TTL.
 *
 * Deletes in small chunks so a large backlog never holds long table locks.
 * A no-op when the cache store is not the database driver.
 */
class PruneExpiredCache extends Command
{
    protected $signature = 'xtra4u:prune-expired-cache {--chunk=1000}';

    protected $description = 'Delete expired rows from the database cache and cache lock tables';

    public function handle(): int
    {
        $store = (string) config('cache.default');
        $config = (array) config("cache.stores.{$store}");

        if (($config['driver'] ?? null) !== 'database') {
            $this->info("Cache store [{$store}] is not database-backed; nothing to prune.");

            return self::SUCCESS;
        }

        $connection = $config['connection'] ?? null;
        $tables = [
            (string) ($config['table'] ?? 'cache') => $connection,
            (string) ($config['lock_table'] ?? 'cache_locks') => $config['lock_connection'] ?? $connection,
        ];
        $chunk = max(1, (int) $this->option('chunk'));
        $now = time();

        foreach ($tables as $table => $conn) {
            $deleted = 0;

            do {
                $batch = DB::connection($conn)->table($table)->where('expiration', '<', $now)->limit($chunk)->delete();
                $deleted += $batch;
            } while ($batch === $chunk);

            $this->info("{$table}: deleted {$deleted} expired rows");
        }

        return self::SUCCESS;
    }
}
