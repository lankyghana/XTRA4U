<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Safety\CacheEngineCheck;
use Illuminate\Support\Facades\DB;

/**
 * Verdicts of the read-only cache-engine check. The InnoDB-versus-MyISAM verdicts are exercised against a real
 * MariaDB in `MariaDbCacheEngineTest` (opt-in); here every driver that needs no server is judged.
 */
class CacheEngineCheckTest extends AiTestCase
{
    /** Judge a cache configuration WITHOUT leaving it active (the harness itself uses the cache in tearDown). */
    private function inspect(string $default, array $store = [], ?string $label = null): array
    {
        $label ??= $default;
        $before = config('cache');
        config(['cache.default' => $label, "cache.stores.{$label}" => $store + ['driver' => $default]]);

        try {
            return (new CacheEngineCheck)->inspect();
        } finally {
            config(['cache' => $before]);
        }
    }

    public function test_unsuitable_drivers_are_rejected_for_a_live_provider(): void
    {
        foreach (['array', 'file', 'null', 'dynamodb'] as $driver) {
            $report = $this->inspect($driver);

            $this->assertFalse($report['suitable'], $driver);
            $this->assertStringContainsString('not suitable', $report['summary'], $driver);
        }
    }

    public function test_redis_and_memcached_are_suitable_by_design_and_say_they_are_untested_here(): void
    {
        foreach (['redis', 'memcached'] as $driver) {
            $report = $this->inspect($driver);

            $this->assertTrue($report['suitable'], $driver);
            $this->assertStringContainsString('do not exercise', $report['summary']);
        }
    }

    public function test_a_database_cache_on_sqlite_is_not_suitable(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite verdict: on MariaDB the same store is judged by MariaDbEngineTest.');
        }

        $report = $this->inspect('database', ['connection' => null, 'table' => 'cache', 'lock_table' => 'cache_locks']);

        $this->assertSame('database', $report['store_driver']);
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertFalse($report['suitable']);
        $this->assertCount(2, $report['tables']);
        $this->assertSame(['cache', 'cache_locks'], array_column($report['tables'], 'table'));
        $this->assertSame([false, false], array_column($report['tables'], 'suitable'), 'SQLite is never accepted, whether or not the tables exist');
    }

    public function test_a_store_whose_label_differs_from_its_driver_is_judged_by_its_driver(): void
    {
        $this->assertTrue($this->inspect('redis', ['connection' => 'cache'], 'shared')['suitable']);
        $this->assertFalse($this->inspect('array', [], 'redis')['suitable'], 'a store LABELLED redis but backed by the array driver is not suitable');
    }

    public function test_the_check_is_memoised_per_instance_for_the_runtime_gate(): void
    {
        $check = new CacheEngineCheck;
        $first = $check->inspectOnce();
        $before = config('cache');
        config(['cache.default' => 'redis', 'cache.stores.redis' => ['driver' => 'redis']]);

        try {
            $this->assertSame($first, $check->inspectOnce());
            $this->assertNotSame($first, $check->inspect());
        } finally {
            config(['cache' => $before]);
        }
    }

    public function test_the_check_never_issues_a_write_or_ddl_statement(): void
    {
        $source = (string) file_get_contents(app_path('Services/Ai/Safety/CacheEngineCheck.php'));

        $this->assertDoesNotMatchRegularExpression('/\b(ALTER|CREATE|DROP|INSERT|UPDATE|DELETE|TRUNCATE|REPLACE)\b\s+(TABLE|INTO|FROM|\w+\s+SET)/i', $source);
        $this->assertStringNotContainsString('Schema::create', $source);
        $this->assertStringNotContainsString('statement(', $source);
        $this->assertStringNotContainsString('unprepared(', $source);
    }
}
