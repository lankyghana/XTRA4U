<?php

namespace App\Services\Ai\Safety;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * READ-ONLY verification that the shared cache can honour what the AI engine relies on: atomic counters
 * (budgets, rate limits) and mutually exclusive locks (concurrency slots, circuit probe).
 *
 * The check never assumes: for the database cache it reads the REAL storage engine of the `cache` and `cache_locks`
 * tables from the server's metadata (a migration having run proves nothing - production's `orders` table was found to be
 * MyISAM, whose locking is a silent no-op). It issues SELECTs only and never alters a table or a row.
 *
 * Verdicts: a driver/engine is "suitable" only when it was positively identified as one that provides atomic
 * increments and real locks:
 *   - database on MariaDB/MySQL with both tables InnoDB  -> suitable (verified by the M5.7 tests on MariaDB 11.4.13)
 *   - redis / memcached                                    -> suitable by design, NOT exercised by this repository's tests
 *   - database on SQLite, any other database, file, array, null, dynamodb, ... -> NOT suitable for a live provider
 */
class CacheEngineCheck
{
    /** @var ?array<string,mixed> */
    private ?array $memo = null;

    /**
     * @return array{driver:string,store_driver:string,connection:?string,suitable:bool,summary:string,tables:list<array{table:string,connection:string,exists:?bool,engine:?string,suitable:bool,note:string}>,notes:list<string>}
     */
    public function inspect(): array
    {
        $default = (string) config('cache.default');
        $store = (array) config("cache.stores.{$default}", []);
        $type = (string) ($store['driver'] ?? $default);

        $report = [
            'driver' => $default,
            'store_driver' => $type,
            'connection' => null,
            'suitable' => false,
            'summary' => '',
            'tables' => [],
            'notes' => [],
        ];

        if (in_array($type, ['redis', 'memcached'], true)) {
            $report['suitable'] = true;
            $report['summary'] = "The {$type} cache provides atomic increments and locks; this repository's tests do not exercise it (requirements: persistence enabled and no eviction of ai:* keys).";

            return $report;
        }

        if ($type !== 'database') {
            $report['summary'] = "The [{$type}] cache driver is not suitable for a live AI provider (atomic counters and cross-process locks are required).";

            return $report;
        }

        $connection = (string) ($store['connection'] ?? config('database.default'));
        $lockConnection = (string) ($store['lock_connection'] ?? $connection);
        $report['connection'] = $connection;

        $all = true;
        foreach ([
            [(string) ($store['table'] ?? 'cache'), $connection],
            [(string) ($store['lock_table'] ?? 'cache_locks'), $lockConnection],
        ] as [$table, $conn]) {
            $row = $this->table($table, $conn);
            $report['tables'][] = $row;
            $all = $all && $row['suitable'];
        }

        $report['suitable'] = $all;
        $report['summary'] = $all
            ? 'The database cache tables exist and use InnoDB.'
            : 'The database cache is not suitable for a live AI provider: see the table verdicts.';

        return $report;
    }

    /** Same as inspect(), computed once per instance (the runtime gate must not query metadata on every request). */
    public function inspectOnce(): array
    {
        return $this->memo ??= $this->inspect();
    }

    /** @return array{table:string,connection:string,exists:?bool,engine:?string,suitable:bool,note:string} */
    private function table(string $table, string $connection): array
    {
        $row = ['table' => $table, 'connection' => $connection, 'exists' => null, 'engine' => null, 'suitable' => false, 'note' => ''];

        try {
            $db = DB::connection($connection);
            $driver = $db->getDriverName();

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $found = $db->selectOne(
                    'SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                    [$db->getDatabaseName(), $db->getTablePrefix().$table]
                );

                $row['exists'] = $found !== null;

                if ($found === null) {
                    $row['note'] = 'The table does not exist.';

                    return $row;
                }

                $row['engine'] = (string) ($found->engine ?? $found->ENGINE ?? '');
                $row['suitable'] = strcasecmp($row['engine'], 'InnoDB') === 0;
                $row['note'] = $row['suitable']
                    ? 'InnoDB: transactional, row-level locking.'
                    : "Engine [{$row['engine']}] does not provide the row-level locking and atomic updates the AI budgets rely on; converting it is a deliberate DBA action (this check never alters a table).";

                return $row;
            }

            $row['exists'] = Schema::connection($connection)->hasTable($table);
            $row['note'] = $row['exists']
                ? "The [{$driver}] database is not a supported production cache backend for the AI engine (only MariaDB/MySQL on InnoDB is accepted; tested on MariaDB 11.4.13 only)."
                : 'The table does not exist.';
        } catch (\Throwable) {
            // Unreachable or unreadable metadata is "not verified", which is not suitable.
            $row['note'] = 'The table could not be inspected.';
        }

        return $row;
    }
}
