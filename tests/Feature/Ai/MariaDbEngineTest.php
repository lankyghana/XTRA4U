<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Preflight\AiPreflight;
use App\Services\Ai\Safety\CacheEngineCheck;
use App\Services\Ai\Tools\Exceptions\ToolWriteBlockedException;
use App\Services\Ai\Tools\ReadOnlyGuard;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Checks that only mean something against a REAL MariaDB/MySQL server: the engine of the database-cache tables, and the
 * server-side statement time limit. Opt-in, because one test creates (and drops) a scratch MyISAM table to prove the
 * engine check rejects it - never point this at a database that matters:
 *
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3317 DB_DATABASE=xtra4u_m57 DB_USERNAME=root DB_PASSWORD= \
 *   AI_MARIADB_TESTS=1 php vendor/bin/phpunit tests/Feature/Ai/MariaDbEngineTest.php
 *
 * The production check itself (CacheEngineCheck) is read-only; the scratch table is the TEST's own fixture.
 */
class MariaDbEngineTest extends AiTestCase
{
    private const SCRATCH = 'ai_scratch_myisam_cache';

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(config('database.default'), ['mysql', 'mariadb'], true) || ! env('AI_MARIADB_TESTS')) {
            $this->markTestSkipped('MariaDB engine tests are opt-in (AI_MARIADB_TESTS=1 on a throwaway MySQL/MariaDB connection).');
        }
    }

    protected function tearDown(): void
    {
        if (in_array(config('database.default'), ['mysql', 'mariadb'], true) && env('AI_MARIADB_TESTS')) {
            DB::statement('DROP TABLE IF EXISTS `'.self::SCRATCH.'`');
        }

        parent::tearDown();
    }

    /** Judge a database cache store WITHOUT leaving it as the default (the harness uses the cache in tearDown). */
    private function inspect(array $store): array
    {
        $before = config('cache');
        config(['cache.default' => 'dbprobe', 'cache.stores.dbprobe' => ['driver' => 'database', 'connection' => null] + $store]);

        try {
            return (new CacheEngineCheck)->inspect();
        } finally {
            config(['cache' => $before]);
        }
    }

    public function test_the_server_is_the_version_the_tests_claim_to_verify(): void
    {
        $version = (string) DB::selectOne('SELECT VERSION() AS v')->v;

        $this->assertStringContainsString('MariaDB', $version);
        $this->assertTrue(version_compare(explode('-', $version)[0], '11.4', '>='), $version);
    }

    public function test_the_real_cache_tables_are_innodb_and_pass(): void
    {
        $report = $this->inspect(['table' => 'cache', 'lock_table' => 'cache_locks']);

        $this->assertTrue($report['suitable'], json_encode($report));
        $this->assertSame(['cache', 'cache_locks'], array_column($report['tables'], 'table'));
        $this->assertSame(['InnoDB', 'InnoDB'], array_column($report['tables'], 'engine'));
        $this->assertSame([true, true], array_column($report['tables'], 'exists'));
    }

    public function test_a_myisam_cache_table_is_rejected_and_never_altered(): void
    {
        DB::statement('DROP TABLE IF EXISTS `'.self::SCRATCH.'`');
        DB::statement('CREATE TABLE `'.self::SCRATCH.'` (`key` VARCHAR(190) NOT NULL PRIMARY KEY, `value` MEDIUMTEXT NOT NULL, `expiration` INT NOT NULL) ENGINE=MyISAM');

        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        $report = $this->inspect(['table' => self::SCRATCH, 'lock_table' => 'cache_locks']);

        $this->assertFalse($report['suitable']);
        $this->assertSame('MyISAM', $report['tables'][0]['engine']);
        $this->assertFalse($report['tables'][0]['suitable']);
        $this->assertTrue($report['tables'][1]['suitable'], 'the other table is judged on its own');
        $this->assertStringContainsString('never alters a table', $report['tables'][0]['note']);

        $engine = DB::selectOne('SELECT ENGINE AS e FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [self::SCRATCH])->e;
        $this->assertSame('MyISAM', $engine, 'the check left the table as it found it');

        foreach ($statements as $sql) {
            $this->assertMatchesRegularExpression('/^\s*select\b/i', $sql, 'the check issued only reads: '.$sql);
        }
        $this->assertNotEmpty($statements);
    }

    public function test_a_missing_cache_table_is_rejected(): void
    {
        $report = $this->inspect(['table' => 'cache_table_that_does_not_exist', 'lock_table' => 'cache_locks']);

        $this->assertFalse($report['suitable']);
        $this->assertFalse($report['tables'][0]['exists']);
        $this->assertNull($report['tables'][0]['engine']);
        $this->assertStringContainsString('does not exist', $report['tables'][0]['note']);
    }

    public function test_a_prefixed_table_name_is_looked_up_with_the_prefix(): void
    {
        $connection = DB::connection();
        $old = $connection->getTablePrefix();
        $connection->setTablePrefix('nopfx_');

        try {
            $report = $this->inspect(['table' => 'cache', 'lock_table' => 'cache_locks']);
        } finally {
            $connection->setTablePrefix($old);
        }

        $this->assertFalse($report['suitable'], 'a prefix that points at no table must not be waved through');
    }

    public function test_preflight_reports_the_real_engines_when_the_database_cache_is_selected(): void
    {
        $before = config('cache');
        config(['cache.default' => 'database']);

        try {
            $checks = [];
            foreach (app(AiPreflight::class)->run()['checks'] as $c) {
                $checks[$c['id']] = $c;
            }
        } finally {
            config(['cache' => $before]);
        }

        $this->assertSame(AiPreflight::PASS, $checks['cache_driver']['status']);
        $this->assertSame(AiPreflight::PASS, $checks['cache_table_cache']['status']);
        $this->assertSame(AiPreflight::PASS, $checks['cache_table_cache_locks']['status']);
        $this->assertStringContainsString('engine=InnoDB', $checks['cache_table_cache']['detail']);
    }

    public function test_the_database_cache_gives_atomic_counters_and_exclusive_locks_on_innodb(): void
    {
        $before = config('cache');
        config(['cache.default' => 'database']);
        Cache::flush();

        try {
            $store = Cache::store('database');
            $this->assertTrue($store->add('ai:test:counter', 0, 60));
            $this->assertFalse($store->add('ai:test:counter', 0, 60), 'add() is atomic');
            $this->assertSame(5, $store->increment('ai:test:counter', 5));
            $this->assertSame(3, $store->decrement('ai:test:counter', 2));

            $a = $store->lock('ai:test:lock', 30);
            $b = $store->lock('ai:test:lock', 30);
            $this->assertTrue($a->get());
            $this->assertFalse($b->get(), 'a second holder is refused while the first holds the lock');
            $a->release();
            $this->assertTrue($b->get());
            $b->release();
        } finally {
            Cache::store('database')->flush();
            config(['cache' => $before]);
        }
    }

    // ------------------------------------------------------------------------------ statement time

    private function sequenceEngineAvailable(): bool
    {
        try {
            return (int) DB::selectOne('SELECT COUNT(*) AS c FROM seq_1_to_3')->c === 3;
        } catch (\Throwable) {
            return false;
        }
    }

    public function test_a_runaway_query_inside_a_tool_is_cut_off_by_the_server_and_the_limit_is_then_lifted(): void
    {
        if (! $this->sequenceEngineAvailable()) {
            $this->markTestSkipped('The MariaDB SEQUENCE engine is not available on this server.');
        }

        config(['ai.tools.limits.max_execution_seconds' => 1]);
        $guard = app(ReadOnlyGuard::class);

        $started = microtime(true);
        try {
            $guard->run(fn () => DB::select('SELECT COUNT(*) AS c FROM seq_1_to_100000 a, seq_1_to_100000 b'));
            $this->fail('the runaway statement must be interrupted by the server');
        } catch (QueryException $e) {
            $this->assertMatchesRegularExpression('/max_statement_time|maximum statement execution time|interrupted/i', $e->getMessage());
        }
        $elapsed = microtime(true) - $started;

        $this->assertLessThan(8.0, $elapsed, 'cut off after about one second, not after the 10^10-row join finishes');
        $this->assertGreaterThanOrEqual(0.9, $elapsed);

        // The limit was a session setting for the duration of the tool call only.
        $this->assertSame(0.0, (float) DB::selectOne('SELECT @@max_statement_time AS t')->t, 'the session limit was reset afterwards');
        $this->assertSame(3, (int) DB::selectOne('SELECT COUNT(*) AS c FROM seq_1_to_3')->c);
    }

    public function test_a_fast_query_inside_a_tool_is_unaffected_and_writes_are_still_refused(): void
    {
        config(['ai.tools.limits.max_execution_seconds' => 1]);
        $guard = app(ReadOnlyGuard::class);

        $this->assertSame(1, (int) $guard->run(fn () => DB::selectOne('SELECT 1 AS one')->one));

        $this->expectException(ToolWriteBlockedException::class);
        $guard->run(fn () => DB::statement('CREATE TABLE ai_must_not_exist (id INT)'));
    }

    public function test_the_limit_is_released_even_when_the_tool_throws(): void
    {
        config(['ai.tools.limits.max_execution_seconds' => 2]);

        try {
            app(ReadOnlyGuard::class)->run(function () {
                $this->assertSame(2.0, (float) DB::selectOne('SELECT @@max_statement_time AS t')->t, 'the bound is active inside the call');

                throw new \RuntimeException('tool failed');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0.0, (float) DB::selectOne('SELECT @@max_statement_time AS t')->t);
    }
}
