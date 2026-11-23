<?php

namespace Tests\Feature\Ai\MultiProcess;

use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Integrity\IntegrityGuard;
use App\Services\Ai\Safety\KillSwitch;
use App\Services\Ai\Usage\BudgetGuard;
use App\Services\Ai\Usage\UsageMeter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\NullHandler;
use Tests\Feature\Ai\Support\DurableAiState;
use Tests\TestCase;

/**
 * M5.7.2 under REAL concurrency: separate PHP processes sharing one database, one cache and one independent anchor directory.
 * Opt-in exactly like AiMultiProcessTest (AI_MULTIPROCESS_TESTS=1 on a migrated MariaDB/MySQL or file-backed SQLite database).
 * Only the offline fake-live provider is used and every worker asserts that no HTTP request was made.
 */
class AnchoredMultiProcessTest extends TestCase
{
    use DurableAiState;

    private const PRICING = ['input_usd_per_million' => 2.0, 'output_usd_per_million' => 6.0, 'as_of' => '2026-01-01'];

    private function sharedDatabase(): bool
    {
        return env('AI_MULTIPROCESS_TESTS')
            && (config('database.default') === 'mysql' || (config('database.default') === 'sqlite' && config('database.connections.sqlite.database') !== ':memory:'));
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->sharedDatabase()) {
            $this->markTestSkipped('Multi-process AI tests are opt-in (AI_MULTIPROCESS_TESTS=1 on a migrated MySQL/MariaDB connection or a file-backed SQLite database).');
        }

        if (config('database.default') === 'sqlite') {
            config(['database.connections.sqlite.busy_timeout' => 30000, 'database.connections.sqlite.journal_mode' => 'wal']);
            DB::purge();
        }

        config(['cache.default' => $this->cacheStore()]);
        DB::table('cache')->delete();
        DB::table('cache_locks')->delete();
        Cache::flush();
        $this->prepareDurableAiState(false);
        $this->useIntegrityAnchor();
        app(KillSwitch::class)->release('multi-process anchored test');
    }

    protected function tearDown(): void
    {
        if ($this->sharedDatabase()) {
            $this->removeAnchorDir();
            config(['ai.integrity.anchor.driver' => 'none']);
            $this->prepareDurableAiState();
            DB::table('cache')->delete();
            DB::table('cache_locks')->delete();
        }

        parent::tearDown();
    }

    private function cacheStore(): string
    {
        return config('database.default') === 'sqlite' ? 'file' : 'database';
    }

    /** @return array<string,mixed> worker config for an anchored, live-flagged but offline provider */
    private function live(array $overrides = []): array
    {
        $plenty = ['daily_requests' => 1_000_000, 'daily_tokens' => 1_000_000_000, 'daily_cost_micro_usd' => 1_000_000_000_000];

        return array_merge([
            'ai.enabled' => true,
            'ai.provider' => 'fakelive',
            'ai.default_model' => 'test-model',
            'ai.pricing.fakelive.test-model' => self::PRICING,
            'ai.surfaces.vendor.enabled' => true,
            'ai.surfaces.customer.enabled' => true,
            'ai.rate_limits.vendor.per_minute' => 100000,
            'ai.rate_limits.customer.per_minute' => 100000,
            'ai.concurrency.max_in_flight' => 100,
            'ai.concurrency.lock_seconds' => 45,
            'ai.request.max_retries' => 0,
            'ai.request.max_output_tokens' => 512,
            'ai.request.timeout_seconds' => 15,
            'ai.request.max_timeout_seconds' => 20,
            'ai.request.deadline_seconds' => 20,
            'ai.request.turn_deadline_seconds' => 40,
            'ai.limits.global' => $plenty,
            'ai.limits.surfaces.vendor' => $plenty,
            'ai.limits.surfaces.customer' => $plenty,
            'ai.limits.surfaces.developer' => $plenty,
            'ai.circuit_breaker' => ['failure_threshold' => 5, 'window_seconds' => 120, 'cooldown_seconds' => 60],
            'ai.logging.channel' => 'null',
            'logging.channels.null' => ['driver' => 'monolog', 'handler' => NullHandler::class],
            'ai.integrity.anchor.driver' => 'file',
            'ai.integrity.anchor.path' => $this->anchorDir,
            'ai.integrity.anchor.key' => self::ANCHOR_KEY,
            'ai.integrity.anchor.independence_attested' => true,
            'ai.integrity.anchor.independence_reference' => 'TEST-INDEPENDENCE-REF',
        ], $overrides);
    }

    /**
     * @param  list<array{0:string,1:array<string,mixed>}>  $specs
     * @return list<array<string,mixed>>
     */
    private function raceMixed(array $specs): array
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ai-mp-'.uniqid();
        mkdir($dir);
        $go = $dir.DIRECTORY_SEPARATOR.'go';

        $procs = [];
        foreach ($specs as $i => [$mode, $args]) {
            $ready = $dir.DIRECTORY_SEPARATOR."ready.{$i}";
            $cmd = [PHP_BINARY, __DIR__.'/worker.php', $mode, json_encode($args + ['ready' => $ready, 'go' => $go, 'index' => $i])];
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), array_merge(getenv(), ['CACHE_STORE' => $this->cacheStore()]));
            $this->assertIsResource($proc);
            $procs[] = ['proc' => $proc, 'pipes' => $pipes, 'ready' => $ready];
        }

        $deadline = microtime(true) + 120;
        foreach ($procs as $p) {
            while (! file_exists($p['ready'])) {
                $this->assertLessThan($deadline, microtime(true), 'a worker never became ready');
                usleep(20_000);
            }
        }

        file_put_contents($go, '1');

        $results = [];
        foreach ($procs as $p) {
            $stdout = stream_get_contents($p['pipes'][1]);
            $stderr = stream_get_contents($p['pipes'][2]);
            proc_close($p['proc']);

            $decoded = json_decode(trim((string) collect(explode("\n", trim((string) $stdout)))->last()), true);
            $this->assertIsArray($decoded, "worker produced no result.\nstdout: {$stdout}\nstderr: {$stderr}");
            $this->assertSame(0, $decoded['http_sent'], 'no HTTP request may be made');
            $results[] = $decoded;
        }

        array_map('unlink', glob($dir.DIRECTORY_SEPARATOR.'*'));
        rmdir($dir);

        return $results;
    }

    /** @return list<array<string,mixed>> */
    private function race(string $mode, int $count, array $args): array
    {
        return $this->raceMixed(array_fill(0, $count, [$mode, $args]));
    }

    /** @return array<string,int> */
    private function tally(array $results): array
    {
        $t = [];
        foreach ($results as $r) {
            foreach ($r['outcomes'] as $o) {
                $t[$o] = ($t[$o] ?? 0) + 1;
            }
        }
        ksort($t);

        return $t;
    }

    private function providerCalls(array $results): int
    {
        return array_sum(array_column($results, 'provider_calls'));
    }

    private function assertAgreement(): void
    {
        $this->assertSame([], app(IntegrityGuard::class)->problems(), 'the database and the anchor agree');
    }

    public function test_concurrent_anchored_reservations_stay_within_a_hard_limit_and_in_both_stores(): void
    {
        $config = $this->live(['ai.limits.global.daily_requests' => 3]);

        $results = $this->race('live', 8, ['surface' => 'vendor', 'attempts' => 1, 'hold_ms' => 300, 'config' => $config]);

        $tally = $this->tally($results);
        $this->assertSame(3, $tally['ok'] ?? 0, json_encode($tally));
        $this->assertSame(5, $tally['ai_budget_exhausted'] ?? 0, json_encode($tally));
        $this->assertSame(3, $this->providerCalls($results));

        $snap = app(IntegrityGuard::class)->anchor()->read();
        $this->assertSame(3, $snap->seq);
        $this->assertSame([1, 2, 3], DB::table(UsageMeter::LEDGER_TABLE)->orderBy('anchor_seq')->pluck('anchor_seq')->map(fn ($v) => (int) $v)->all(), 'gap-free, unique sequence numbers');
        $this->assertSame(3, $snap->totals(app(UsageMeter::class)->today())['global']['requests']);
        $this->assertAgreement();
    }

    public function test_cache_clearing_during_an_anchored_race_neither_bypasses_a_control_nor_returns_budget(): void
    {
        $config = $this->live(['ai.limits.global.daily_requests' => 6]);
        $worker = ['surface' => 'vendor', 'attempts' => 4, 'hold_ms' => 100, 'config' => $config];

        $results = $this->raceMixed([
            ['live', $worker], ['live', $worker], ['live', $worker], ['live', $worker], ['live', $worker], ['live', $worker],
            ['flush', ['duration_ms' => 3000, 'config' => $config]],
        ]);

        $this->assertGreaterThan(10, array_sum(array_column($results, 'flushes')));
        $this->assertSame(6, $this->tally($results)['ok'] ?? 0, json_encode($this->tally($results)));
        $this->assertSame(6, $this->providerCalls($results));
        $this->assertSame(6, app(IntegrityGuard::class)->anchor()->read()->seq);
        $this->assertAgreement();
    }

    public function test_an_unreachable_anchor_prevents_every_provider_transmission(): void
    {
        $config = $this->live();

        $results = $this->race('live', 6, ['surface' => 'vendor', 'attempts' => 2, 'config' => $config, 'fault' => ['anchor_reserve' => true]]);

        $this->assertSame(0, $this->providerCalls($results), 'nothing was sent');
        $this->assertSame(['ai_misconfigured' => 12], $this->tally($results));
        $this->assertSame(0, DB::table(UsageMeter::LEDGER_TABLE)->count());
        $this->assertSame(0, (int) DB::table(UsageMeter::COUNTERS_TABLE)->sum('requests'), 'the counters rolled back with every refused reservation');
        $this->assertSame(0, app(IntegrityGuard::class)->anchor()->read()->seq);
        $this->assertAgreement();
    }

    public function test_a_database_failure_after_the_anchor_accepted_stays_charged_and_blocks_everyone_else(): void
    {
        $config = $this->live();

        $results = $this->race('live', 4, ['surface' => 'vendor', 'attempts' => 1, 'config' => $config, 'fault' => ['db_after_anchor' => true]]);

        $this->assertSame(0, $this->providerCalls($results), 'nothing was sent');
        $this->assertSame(0, DB::table(UsageMeter::LEDGER_TABLE)->count(), 'the database holds nothing');

        $snap = app(IntegrityGuard::class)->anchor()->read();
        $this->assertSame(1, $snap->seq, 'exactly one reservation reached the anchor: everyone after it found the histories disagreeing');
        $this->assertSame(1, $snap->totals(app(UsageMeter::class)->today())['global']['requests'], 'and it remains charged');
        $this->assertTrue(app(KillSwitch::class)->engaged());

        // The recovery path: explicit, never automatic.
        $this->assertSame(0, Artisan::call('ai:integrity', ['action' => 'reconcile', '--reason' => 'multi-process orphan recovery']));
        $this->assertSame(1, DB::table(UsageMeter::LEDGER_TABLE)->count());
        $this->assertTrue(app(KillSwitch::class)->engaged(), 'still stopped');
        $this->assertAgreement();
    }

    public function test_duplicate_settlement_by_many_processes_is_applied_once_in_the_database_and_the_anchor(): void
    {
        config($this->live());
        $r = app(BudgetGuard::class)->reserve(AiSurface::Vendor, 1000, true, 400);

        $results = $this->race('settle', 8, ['id' => $r->id, 'day' => $r->day, 'surface' => 'vendor', 'tokens' => 1000, 'cost' => 400, 'config' => $this->live()]);

        $this->assertSame(['ok' => 8], $this->tally($results));

        $row = DB::table(UsageMeter::LEDGER_TABLE)->where('id', $r->id)->first();
        $this->assertSame('settled', $row->state);
        $anchored = app(IntegrityGuard::class)->anchor()->read()->reservation($r->id);
        $this->assertSame('settled', $anchored['state']);
        $this->assertSame((int) $row->tokens, $anchored['tokens'], 'the SAME process won in both stores');
        $this->assertSame(1, (int) DB::table(UsageMeter::COUNTERS_TABLE)->where('scope', 'global')->value('successes'));
        $this->assertAgreement();
    }

    public function test_an_older_database_restored_under_fresh_processes_cannot_reactivate_ai(): void
    {
        $config = $this->live();
        $this->assertSame(['ok' => 1], $this->tally($this->race('live', 1, ['surface' => 'vendor', 'attempts' => 1, 'config' => $config])));

        $olderReleased = $this->snapshotDatabase();
        app(KillSwitch::class)->engage('incident');
        $this->restoreDatabase($olderReleased); // the database "comes back" released

        Cache::flush();
        $stopped = $this->race('killswitch', 4, ['config' => $config]);
        $this->assertSame(['engaged' => 4], $this->tally($stopped), 'brand-new processes all find AI stopped');

        $refused = $this->race('live', 3, ['surface' => 'vendor', 'attempts' => 2, 'config' => $config]);
        $this->assertSame(0, $this->providerCalls($refused));
        $this->assertSame(['ai_disabled' => 6], $this->tally($refused));
    }

    public function test_after_a_detected_rollback_and_an_explicit_recovery_fresh_processes_resume_only_when_released(): void
    {
        $config = $this->live();
        $this->race('live', 2, ['surface' => 'vendor', 'attempts' => 1, 'config' => $config]);
        $this->assertSame(2, DB::table(UsageMeter::LEDGER_TABLE)->count());

        DB::table(UsageMeter::LEDGER_TABLE)->delete();
        DB::table(UsageMeter::COUNTERS_TABLE)->delete();

        $blocked = $this->race('live', 3, ['surface' => 'vendor', 'attempts' => 1, 'config' => $config]);
        $this->assertSame(0, $this->providerCalls($blocked), 'detected by every process: nothing sent');
        $this->assertSame(0, array_sum(array_map(fn ($r) => in_array('ok', $r['outcomes'], true) ? 1 : 0, $blocked)));

        $this->assertSame(0, Artisan::call('ai:integrity', ['action' => 'reconcile', '--reason' => 'multi-process recovery']));
        $stillStopped = $this->race('live', 2, ['surface' => 'vendor', 'attempts' => 1, 'config' => $config]);
        $this->assertSame(0, $this->providerCalls($stillStopped), 'reconciled but not released');

        app(KillSwitch::class)->release('recovered and reconciled with the provider report');
        $resumed = $this->race('live', 2, ['surface' => 'vendor', 'attempts' => 1, 'config' => $config]);
        $this->assertSame(['ok' => 2], $this->tally($resumed));
        $this->assertSame(4, (int) DB::table(UsageMeter::COUNTERS_TABLE)->where('scope', 'global')->value('requests'), 'the lost spend was restored, never reset');
        $this->assertAgreement();
    }
}
