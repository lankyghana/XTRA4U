<?php

namespace Tests\Feature\Ai\Integrity;

use App\Services\Ai\Exceptions\AiBusyException;
use App\Services\Ai\Exceptions\AiCircuitOpenException;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Exceptions\AiRateLimitedException;
use App\Services\Ai\Safety\CircuitBreaker;
use App\Services\Ai\Safety\ConcurrencyGuard;
use App\Services\Ai\Usage\UsageMeter;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M5.7.2 cache-control inventory. The rate limits, the concurrency slots and the circuit breaker are CACHE based. These tests pin
 * down exactly what a cache clear, a lock-store loss and a cache outage do to them, and prove that none of it can touch the money
 * or the emergency stop: spend is bounded by the durable ledger and the anchor whatever the cache does.
 */
class CacheControlsTest extends IntegrityTestCase
{
    /** The framework's cache tables (the shared test database does not create them unless asked). */
    private function useDatabaseCache(): void
    {
        if (! Schema::hasTable('cache')) {
            (require base_path('database/migrations/0001_01_01_000001_create_cache_table.php'))->up();
        }

        config(['cache.default' => 'database']);
        Cache::forgetDriver();
        $this->app->forgetInstance(ConcurrencyGuard::class);
        DB::table('cache')->delete();
        DB::table('cache_locks')->delete();
    }

    public function test_an_unavailable_cache_stops_the_request_before_any_reservation_or_provider_call(): void
    {
        $this->turn(); // healthy once

        Cache::extend('broken', fn () => new Repository(new class implements Store
        {
            private function down(): never
            {
                throw new \RuntimeException('cache backend unavailable');
            }

            public function get($key)
            {
                $this->down();
            }

            public function many(array $keys)
            {
                $this->down();
            }

            public function put($key, $value, $seconds)
            {
                $this->down();
            }

            public function putMany(array $values, $seconds)
            {
                $this->down();
            }

            public function increment($key, $value = 1)
            {
                $this->down();
            }

            public function decrement($key, $value = 1)
            {
                $this->down();
            }

            public function forever($key, $value)
            {
                $this->down();
            }

            public function forget($key)
            {
                $this->down();
            }

            public function flush()
            {
                $this->down();
            }

            public function getPrefix()
            {
                return '';
            }
        }));
        config(['cache.stores.broken' => ['driver' => 'broken'], 'cache.default' => 'broken']);
        Cache::forgetDriver();
        $this->app->forgetInstance(ConcurrencyGuard::class);

        $calls = $this->live->calls;
        $rows = $this->ledgerRows();
        $seq = $this->anchor()->read()->seq;

        try {
            $this->turn();
            $this->fail('a safety control is unavailable: the request must be refused');
        } catch (\Throwable $e) {
            $this->assertNotInstanceOf(AiProviderException::class, $e, 'refused before the provider');
        } finally {
            config(['cache.default' => 'array']);
            Cache::forgetDriver();
            $this->app->forgetInstance(ConcurrencyGuard::class);
        }

        $this->assertSame($calls, $this->live->calls);
        $this->assertSame($rows, $this->ledgerRows(), 'nothing was reserved');
        $this->assertSame($seq, $this->anchor()->read()->seq, 'and the anchor heard nothing');
    }

    public function test_clearing_the_cache_during_a_request_changes_neither_the_budget_nor_the_anchor(): void
    {
        config(['ai.limits.global.daily_requests' => 3]);
        $this->live->queue(function () {
            Cache::flush();
            $this->artisan('cache:clear')->assertExitCode(0);

            return new \App\Services\Ai\Data\AiResponse('x', 'fakelive', 'test-model', new \App\Services\Ai\Data\AiUsage(10, 5));
        });

        $this->turn();

        $this->assertSame(1, $this->counter('global', 'requests'));
        $this->assertSame(1, $this->anchor()->read()->totals($this->today())['global']['requests']);
        $this->assertFalse(app(\App\Services\Ai\Safety\KillSwitch::class)->engaged());
        $this->assertSame([], $this->guard()->problems());
    }

    public function test_repeated_cache_loss_cannot_exceed_a_hard_budget_limit(): void
    {
        config(['ai.limits.global.daily_requests' => 3]);
        $this->useDatabaseCache();

        $reached = 0;
        for ($i = 0; $i < 8; $i++) {
            Cache::flush();                    // cache:clear
            DB::table('cache_locks')->delete(); // lock store lost as well (restart / restore / truncate)

            try {
                $this->turn("attempt {$i}");
                $reached++;
            } catch (\App\Services\Ai\Exceptions\AiBudgetExceededException) {
            }
        }

        $this->assertSame(3, $reached, 'exactly the budgeted number reached the provider');
        $this->assertSame(3, $this->live->calls);
        $this->assertSame(3, $this->anchor()->read()->totals($this->today())['global']['requests']);
    }

    public function test_cache_clear_on_the_database_store_resets_rate_limits_and_the_breaker_but_not_the_concurrency_slots(): void
    {
        $this->useDatabaseCache();
        config(['ai.rate_limits.vendor.per_minute' => 1, 'ai.concurrency.max_in_flight' => 2]);

        // rate limit
        $this->turn();
        try {
            $this->turn();
            $this->fail('over the per-principal limit');
        } catch (AiRateLimitedException) {
        }
        Cache::flush();
        $this->turn(); // the counter was reset by the clear
        $this->assertSame(2, $this->live->calls);

        // circuit breaker
        $breaker = app(CircuitBreaker::class);
        foreach (range(1, 3) as $_) {
            $breaker->recordFailure($breaker->assertAllows('fakelive'));
        }
        try {
            $breaker->assertAllows('fakelive');
            $this->fail('the circuit is open');
        } catch (AiCircuitOpenException) {
        }
        Cache::flush();
        $this->assertNotNull($breaker->assertAllows('fakelive'), 'the open circuit was forgotten by the clear');

        // concurrency slots live in the LOCK table, which `cache:clear` does not touch
        $guard = app(ConcurrencyGuard::class);
        $a = $guard->acquire();
        $b = $guard->acquire();
        Cache::flush();
        try {
            $guard->acquire();
            $this->fail('the slots must survive a cache clear');
        } catch (AiBusyException) {
        }
        $a->release();
        $b->release();
    }

    public function test_losing_the_lock_store_can_briefly_double_the_in_flight_capacity_but_never_more_and_never_the_budget(): void
    {
        $this->useDatabaseCache();
        config(['ai.concurrency.max_in_flight' => 2]);
        $guard = app(ConcurrencyGuard::class);

        $held = [$guard->acquire(), $guard->acquire()];

        try {
            $guard->acquire();
            $this->fail('full');
        } catch (AiBusyException) {
        }

        // A lock-store loss (Redis restart/flush, a restore of the cache_locks table, a truncate): the two holders are forgotten.
        DB::table('cache_locks')->delete();

        $after = [$guard->acquire(), $guard->acquire()];
        $this->assertCount(4, array_merge($held, $after), 'capacity can overlap: up to 2x max_in_flight in flight');

        try {
            $guard->acquire();
            $this->fail('and no further: the new generation is capped again');
        } catch (AiBusyException) {
        }

        // The overlap is a capacity (worker) risk only: the ledger and the anchor are not in the cache.
        $this->assertSame([], $this->guard()->problems());
        $this->assertSame(0, $this->counter('global', 'requests'));
        foreach (array_merge($held, $after) as $slot) {
            $slot->release();
        }
    }

    public function test_the_budget_and_the_anchor_are_not_stored_in_the_cache_at_all(): void
    {
        foreach ([
            'Services/Ai/Usage/BudgetGuard.php', 'Services/Ai/Safety/KillSwitch.php', 'Services/Ai/Integrity/IntegrityGuard.php',
            'Services/Ai/Integrity/FileIntegrityAnchor.php', 'Services/Ai/Integrity/AnchorSnapshot.php',
        ] as $file) {
            $this->assertDoesNotMatchRegularExpression('/\bCache::|cache\(|->cache\b/', (string) file_get_contents(app_path($file)), $file);
        }
    }

    public function test_the_meter_counters_are_untouched_by_a_cache_clear(): void
    {
        $this->turn();
        $before = DB::table(UsageMeter::COUNTERS_TABLE)->orderBy('scope')->get()->map(fn ($r) => (array) $r)->all();

        Cache::flush();
        $this->artisan('cache:clear')->assertExitCode(0);

        $this->assertSame($before, DB::table(UsageMeter::COUNTERS_TABLE)->orderBy('scope')->get()->map(fn ($r) => (array) $r)->all());
    }
}
