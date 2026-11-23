<?php

namespace Tests\Feature\Ai\MultiProcess;

use App\Services\Ai\Data\AiMessage;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Safety\KillSwitch;
use App\Services\Ai\Usage\BudgetGuard;
use App\Services\Ai\Usage\PriceTable;
use App\Services\Ai\Usage\TokenEstimator;
use App\Services\Ai\Usage\UsageMeter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\NullHandler;
use Tests\Feature\Ai\Support\DurableAiState;
use Tests\TestCase;

/**
 * REAL multi-process safety of the AI engine on the production cache store (the `database` driver), against
 * MySQL / MariaDB (or, for the same scenarios, a file-backed SQLite database). Single-process tests cannot prove any of this: they simulate "a second request" by re-entering
 * the engine, which says nothing about two PHP processes racing on the same cache rows.
 *
 * Each scenario starts N independent `php worker.php` processes. They boot, announce "ready", and are released
 * together by a "go" file, so they genuinely contend. Opt-in (needs a migrated MySQL/MariaDB database):
 *
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3308 DB_DATABASE=xtra4u_ai DB_USERNAME=root DB_PASSWORD= \
 *   AI_MULTIPROCESS_TESTS=1 php vendor/bin/phpunit tests/Feature/Ai/MultiProcess
 *
 * Only the offline mock provider is used and the workers assert that no HTTP request was made. The tests truncate the
 * `cache` and `cache_locks` tables of the database they run against: never point them at real data.
 */
class AiMultiProcessTest extends TestCase
{
    use DurableAiState;

    /** A database several processes can share: MySQL/MariaDB, or SQLite in a FILE (never :memory:). */
    private function sharedDatabase(): bool
    {
        if (! env('AI_MULTIPROCESS_TESTS')) {
            return false;
        }

        return config('database.default') === 'mysql'
            || (config('database.default') === 'sqlite' && config('database.connections.sqlite.database') !== ':memory:');
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
        $this->resetSharedState();
    }

    /**
     * The shared cache the workers use. MariaDB: the production `database` store. A file-backed SQLite database cannot host that
     * store safely across processes (the engine's own cache check rejects it for live use), so the SQLite run keeps the cache on
     * disk and exercises what this milestone is about: the DURABLE budget ledger and kill switch, which live in the database.
     */
    private function cacheStore(): string
    {
        return config('database.default') === 'sqlite' ? 'file' : 'database';
    }

    /** Rate limits, concurrency slots and the circuit breaker are cache-based and are exact only on the production cache store. */
    private function requiresDatabaseCache(): void
    {
        if ($this->cacheStore() !== 'database') {
            $this->markTestSkipped('Cache-based protection (rate limit, slots, breaker) is verified on the production database cache store (MariaDB).');
        }
    }

    protected function tearDown(): void
    {
        if ($this->sharedDatabase()) {
            $this->removeAnchorDir(); // M5.7.2: a live-flagged scenario created a throw-away anchor; the database goes back to "no anchor"
            config(['ai.integrity.anchor.driver' => 'none']);
            $this->resetSharedState();
        }

        parent::tearDown();
    }

    private function resetSharedState(): void
    {
        DB::table('cache')->delete();
        DB::table('cache_locks')->delete();
        Cache::flush();
        $this->prepareDurableAiState(); // empty budget ledger, kill switch released
    }

    /** Settings every scenario starts from: engine on, mock provider, nothing limiting unless the scenario says so. */
    private function base(array $overrides = []): array
    {
        return array_merge([
            'ai.enabled' => true,
            'ai.provider' => 'mock',
            'ai.default_model' => null,
            'ai.surfaces.vendor.enabled' => true,
            'ai.surfaces.customer.enabled' => true,
            'ai.rate_limits.vendor.per_minute' => 100000,
            'ai.rate_limits.customer.per_minute' => 100000,
            'ai.concurrency.max_in_flight' => 100,
            'ai.concurrency.lock_seconds' => 45,
            'ai.request.max_retries' => 0,
            'ai.circuit_breaker' => ['failure_threshold' => 5, 'window_seconds' => 120, 'cooldown_seconds' => 60],
            'ai.pricing' => [],
            'ai.logging.channel' => 'null',
            'logging.channels.null' => ['driver' => 'monolog', 'handler' => NullHandler::class],
        ], $overrides);
    }

    /**
     * Start `$count` workers, wait until every one has booted, release them together, and collect their results.
     *
     * @return list<array<string,mixed>>
     */
    private function race(string $mode, int $count, array $args): array
    {
        return $this->raceMixed(array_fill(0, $count, [$mode, $args]));
    }

    /**
     * Like race(), with a different mode/arguments per process (e.g. workers that reserve plus one that clears the cache).
     *
     * @param  list<array{0:string,1:array<string,mixed>}>  $specs  one [mode, args] per worker process
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
            // CACHE_STORE must be 'database' from the very first line of the worker's boot, exactly as in production:
            // singletons built during bootstrap (the rate limiter among them) bind to the default store at that moment.
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

        file_put_contents($go, '1'); // everyone is booted: release them within a millisecond or two of each other

        $results = [];
        foreach ($procs as $p) {
            $stdout = stream_get_contents($p['pipes'][1]);
            $stderr = stream_get_contents($p['pipes'][2]);
            proc_close($p['proc']);

            $decoded = json_decode(trim((string) collect(explode("\n", trim((string) $stdout)))->last()), true);
            $this->assertIsArray($decoded, "worker produced no result.\nstdout: {$stdout}\nstderr: {$stderr}");
            $this->assertSame(0, $decoded['http_sent'], 'the mock provider must never make an HTTP request');
            $results[] = $decoded;
        }

        array_map('unlink', glob($dir.DIRECTORY_SEPARATOR.'*'));
        rmdir($dir);

        return $results;
    }

    /** @param list<array<string,mixed>> $results @return array<string,int> */
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

    /** Highest number of provider calls that were running at the same moment, from the workers' own timestamps. */
    private function peakOverlap(array $results): int
    {
        $events = [];
        foreach ($results as $r) {
            foreach ($r['intervals'] as [$start, $end]) {
                $events[] = [$start, 1];
                $events[] = [$end, -1];
            }
        }
        usort($events, fn ($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]); // ends before starts at the same instant

        $now = $peak = 0;
        foreach ($events as [, $delta]) {
            $peak = max($peak, $now += $delta);
        }

        return $peak;
    }

    private function usage(): array
    {
        return app(UsageMeter::class)->snapshot();
    }

    /** The reservation the engine makes for the identical request the workers send. */
    private function estimate(string $text = 'Hello there'): int
    {
        $req = (new AiRequest(AiSurface::Vendor, 'p', [AiMessage::user($text)]))->resolved('mock-model', 512, 20);

        return app(TokenEstimator::class)->estimate($req);
    }

    // ------------------------------------------------------------------ concurrency guard

    public function test_the_in_flight_limit_cannot_be_exceeded_by_simultaneous_processes(): void
    {
        $this->requiresDatabaseCache();
        foreach ([[1, 6], [3, 8]] as [$max, $workers]) {
            $this->resetSharedState();

            $results = $this->race('engine', $workers, [
                'surface' => 'vendor', 'attempts' => 1, 'hold_ms' => 1500,
                'config' => $this->base(['ai.concurrency.max_in_flight' => $max]),
            ]);

            $tally = $this->tally($results);
            $this->assertSame($max, $tally['ok'] ?? 0, "exactly {$max} of {$workers} simultaneous requests may run: ".json_encode($tally));
            $this->assertSame($workers - $max, $tally['ai_busy'] ?? 0, json_encode($tally));
            $this->assertLessThanOrEqual($max, $this->peakOverlap($results), 'provider calls actually overlapped beyond the limit');
            $this->assertSame(0, DB::table('cache_locks')->where('key', 'like', '%ai:slot:%')->where('expiration', '>', time())->count(), 'every slot was released');
            $this->assertSame($max, $this->usage()['global']['requests'], 'busy refusals consumed no budget');
        }
    }

    // ------------------------------------------------------------------ budgets

    public function test_the_global_request_budget_cannot_be_overspent_by_simultaneous_processes(): void
    {
        $results = $this->race('engine', 8, [
            'surface' => 'vendor', 'attempts' => 4,
            'config' => $this->base(['ai.limits.global.daily_requests' => 5]),
        ]);

        $tally = $this->tally($results);
        $this->assertSame(5, $tally['ok'] ?? 0, json_encode($tally));
        $this->assertSame(27, $tally['ai_budget_exhausted'] ?? 0, json_encode($tally));
        $this->assertSame([], array_diff(array_keys($tally), ['ok', 'ai_budget_exhausted']), 'nothing unexpected: '.json_encode($tally));

        $u = $this->usage();
        $this->assertSame(5, $u['global']['requests'], 'rolled-back reservations net out exactly');
        $this->assertSame(5, $u['surface.vendor']['requests']);
        $this->assertSame(5, $u['global']['successes']);
    }

    public function test_the_global_token_budget_cannot_be_overspent_by_simultaneous_processes(): void
    {
        $e = $this->estimate();

        $results = $this->race('engine', 8, [
            'surface' => 'vendor', 'attempts' => 3, 'unknown_usage' => true, // unknown usage: each success keeps its full estimate
            'config' => $this->base(['ai.limits.global.daily_tokens' => 3 * $e + 5]),
        ]);

        $tally = $this->tally($results);
        $this->assertSame(3, $tally['ok'] ?? 0, 'the budget holds exactly three estimates: '.json_encode($tally));
        $u = $this->usage();
        $this->assertSame(3 * $e, $u['global']['tokens'], 'no residue from the refused reservations');
        $this->assertSame(3, $u['global']['requests']);
        $this->assertLessThanOrEqual(3 * $e + 5, $u['global']['tokens']);
    }

    public function test_the_surface_budget_binds_even_when_the_global_one_is_plentiful_and_leaves_no_partial_reservation(): void
    {
        $e = $this->estimate();

        $results = $this->race('engine', 8, [
            'surface' => 'vendor', 'attempts' => 3, 'unknown_usage' => true,
            'config' => $this->base([
                'ai.limits.global.daily_tokens' => 1_000_000_000,
                'ai.limits.global.daily_requests' => 1_000_000,
                'ai.limits.surfaces.vendor.daily_tokens' => 2 * $e + 5,
            ]),
        ]);

        $tally = $this->tally($results);
        $this->assertSame(2, $tally['ok'] ?? 0, json_encode($tally));
        $u = $this->usage();
        $this->assertSame(2 * $e, $u['surface.vendor']['tokens']);
        $this->assertSame(2 * $e, $u['global']['tokens'], 'global reservations taken before a surface refusal were all rolled back');
        $this->assertSame(2, $u['global']['requests']);
        $this->assertSame(2, $u['surface.vendor']['requests']);
    }

    // ------------------------------------------------------------------ rate limiter

    public function test_the_rate_limit_is_exact_across_processes(): void
    {
        $this->requiresDatabaseCache();
        $results = $this->race('engine', 8, [
            'surface' => 'vendor', 'attempts' => 3, 'shared_principal' => 'vendor:shared',
            'config' => $this->base(['ai.rate_limits.vendor.per_minute' => 5]),
        ]);

        $tally = $this->tally($results);
        $this->assertSame(5, $tally['ok'] ?? 0, 'the per-minute allowance must not be exceeded even when everyone arrives together: '.json_encode($tally));
        $this->assertSame(19, $tally['ai_rate_limited'] ?? 0, json_encode($tally));
    }

    // ------------------------------------------------------------------ circuit breaker

    public function test_only_one_process_gets_the_half_open_probe(): void
    {
        $this->requiresDatabaseCache();
        Cache::put('ai:cb:mock:open_until', now()->getTimestamp() - 5, 86400); // cooldown already elapsed

        $results = $this->race('probe', 8, ['hold_ms' => 1000, 'config' => $this->base()]);

        $tally = $this->tally($results);
        $this->assertSame(1, $tally['probe'] ?? 0, json_encode($tally));
        $this->assertSame(7, $tally['open'] ?? 0, json_encode($tally));
    }

    // ------------------------------------------------------------------ strict hard budgets (M5.7), live-flagged provider

    private const LIVE_PRICING = ['input_usd_per_million' => 2.0, 'output_usd_per_million' => 6.0, 'as_of' => '2026-01-01'];

    /** Worker settings for a live-flagged (still offline) provider: every limit explicit and finite, as production requires. */
    private function liveBase(array $overrides = []): array
    {
        $plenty = ['daily_requests' => 1_000_000, 'daily_tokens' => 1_000_000_000, 'daily_cost_micro_usd' => 1_000_000_000_000];

        // M5.7.2: a live-flagged provider is refused without an independent anchor, so every live scenario gets a throw-away one
        // (empty, bound to the freshly emptied database, kill switch released) and hands its location to the workers.
        if ($this->anchorDir === null) {
            $this->useIntegrityAnchor();
            app(KillSwitch::class)->release('multi-process live scenario');
        }

        return $overrides + $this->base([
            'ai.integrity.anchor.driver' => 'file',
            'ai.integrity.anchor.path' => $this->anchorDir,
            'ai.integrity.anchor.key' => self::ANCHOR_KEY,
            'ai.integrity.anchor.independence_attested' => true,
            'ai.integrity.anchor.independence_reference' => 'TEST-INDEPENDENCE-REF',
            'ai.provider' => 'fakelive',
            'ai.default_model' => 'test-model',
            'ai.pricing.fakelive.test-model' => self::LIVE_PRICING,
            'ai.request.max_output_tokens' => 512,
            'ai.request.timeout_seconds' => 15,
            'ai.request.max_timeout_seconds' => 20,
            'ai.request.deadline_seconds' => 20,
            'ai.request.turn_deadline_seconds' => 40,
            'ai.limits.global' => $plenty,
            'ai.limits.surfaces.vendor' => $plenty,
            'ai.limits.surfaces.customer' => $plenty,
            'ai.limits.surfaces.developer' => $plenty,
        ]);
    }

    /**
     * A worker's config array is applied key by key with dotted paths, so a scenario override of a nested key must come
     * AFTER the whole-scope defaults it refines: array order is preserved, and base keys first.
     *
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function live(array $overrides = []): array
    {
        $base = $this->liveBase();

        return array_merge($base, $overrides);
    }

    /** What ONE request of the standard test text reserves in the workers: [tokens, max cost in micro-USD]. */
    private function reservation(string $text = 'Hello there'): array
    {
        config(['ai.pricing.fakelive.test-model' => self::LIVE_PRICING, 'ai.request.max_output_tokens' => 512]);

        $request = (new AiRequest(AiSurface::Vendor, 'p', [AiMessage::user($text)]))->resolved('test-model', 512, 15);
        $estimator = app(TokenEstimator::class);
        $cost = app(PriceTable::class)->maxCostMicroUsd('fakelive', 'test-model', $estimator->inputUpperBound($request), $estimator->outputUpperBound($request));
        $this->assertNotNull($cost);
        $this->assertGreaterThan(0, $cost);

        return [$estimator->estimate($request), $cost];
    }

    public function test_a_hard_cost_cap_is_never_overshot_by_simultaneous_live_requests(): void
    {
        [$tokens, $cost] = $this->reservation();
        $limit = 3 * $cost + 50;

        $results = $this->race('live', 8, [
            'surface' => 'vendor', 'attempts' => 1, 'hold_ms' => 900,
            'config' => $this->live(['ai.limits.global.daily_cost_micro_usd' => $limit]),
        ]);

        $tally = $this->tally($results);
        $this->assertSame(3, $tally['ok'] ?? 0, 'only the requests whose worst case fits are admitted: '.json_encode($tally));
        $this->assertSame(5, $tally['ai_budget_exhausted'] ?? 0, json_encode($tally));
        $this->assertSame([], array_diff(array_keys($tally), ['ok', 'ai_budget_exhausted']), json_encode($tally));
        $this->assertLessThanOrEqual(3, $this->peakOverlap($results), 'never more calls in flight than reservations that fit');

        $u = $this->usage();
        $this->assertLessThanOrEqual($limit, $u['global']['cost_micro_usd'], 'the counter never exceeded the limit');
        $this->assertSame(3 * 50, $u['global']['cost_micro_usd'], 'settled to the reported usage: 10 x $2/M + 5 x $6/M = 50 micro-USD each');
        $this->assertSame(3 * 15, $u['global']['tokens']);
        $this->assertSame(3, $u['global']['requests'], 'refused reservations netted out');
        $this->assertSame(3, array_sum(array_column($results, 'provider_calls')), 'the provider was only reached by admitted requests');
    }

    public function test_unknown_usage_keeps_the_full_reserved_cost_so_the_cap_still_holds(): void
    {
        [$tokens, $cost] = $this->reservation();

        $results = $this->race('live', 8, [
            'surface' => 'vendor', 'attempts' => 3, 'unknown_usage' => true,
            'config' => $this->live(['ai.limits.global.daily_cost_micro_usd' => 3 * $cost + 50]),
        ]);

        $tally = $this->tally($results);
        $this->assertSame(3, $tally['ok'] ?? 0, json_encode($tally));
        $this->assertSame(21, $tally['ai_budget_exhausted'] ?? 0, json_encode($tally));

        $u = $this->usage();
        $this->assertSame(3 * $cost, $u['global']['cost_micro_usd'], 'each unknown-usage success keeps its whole worst-case cost');
        $this->assertSame(3 * $tokens, $u['global']['tokens']);
        $this->assertLessThanOrEqual(3 * $cost + 50, $u['global']['cost_micro_usd']);
    }

    public function test_a_surface_cost_cap_binds_and_rolls_back_the_global_reservation(): void
    {
        [$tokens, $cost] = $this->reservation();

        $results = $this->race('live', 8, [
            'surface' => 'vendor', 'attempts' => 3, 'unknown_usage' => true,
            'config' => $this->live(['ai.limits.surfaces.vendor.daily_cost_micro_usd' => 2 * $cost + 50]),
        ]);

        $tally = $this->tally($results);
        $this->assertSame(2, $tally['ok'] ?? 0, json_encode($tally));

        $u = $this->usage();
        $this->assertSame(2 * $cost, $u['surface.vendor']['cost_micro_usd']);
        $this->assertSame(2 * $cost, $u['global']['cost_micro_usd'], 'the global reservation taken before the surface refusal was rolled back');
        $this->assertSame(2 * $tokens, $u['global']['tokens']);
        $this->assertSame(2, $u['global']['requests']);
    }

    public function test_a_hard_request_cap_is_exact_for_a_live_provider(): void
    {
        $results = $this->race('live', 8, [
            'surface' => 'vendor', 'attempts' => 4,
            'config' => $this->live(['ai.limits.global.daily_requests' => 5]),
        ]);

        $tally = $this->tally($results);
        $this->assertSame(5, $tally['ok'] ?? 0, json_encode($tally));
        $this->assertSame(27, $tally['ai_budget_exhausted'] ?? 0, json_encode($tally));
        $this->assertSame(5, $this->usage()['global']['requests']);
        $this->assertSame(5, array_sum(array_column($results, 'provider_calls')));
    }

    // ------------------------------------------------------------------ durable budget (M5.7.1)

    /** The durable counters and the ledger must agree exactly after any race. */
    private function assertLedgerConsistent(string $why = ''): void
    {
        $this->assertSame([], app(BudgetGuard::class)->integrityProblems(), 'counters equal the ledger. '.$why);
    }

    public function test_lost_or_damaged_accounting_stops_a_live_provider_instead_of_resetting_the_budget(): void
    {
        $config = $this->live();

        $first = $this->race('live', 1, ['surface' => 'vendor', 'attempts' => 1, 'config' => $config]);
        $this->assertSame(['ok' => 1], $this->tally($first));
        $this->assertSame(1, $this->usage()['global']['requests']);

        // Someone zeroes the counters (a bad restore, a manual UPDATE): the ledger still remembers the request.
        DB::table('ai_usage_counters')->where('scope', 'global')->update(['requests' => 0, 'tokens' => 0, 'cost_micro_usd' => 0]);

        $second = $this->race('live', 2, ['surface' => 'vendor', 'attempts' => 2, 'config' => $config]);

        $this->assertArrayNotHasKey('ok', $this->tally($second), 'no request is admitted on top of unknown usage: '.json_encode($this->tally($second)));
        $this->assertSame(0, array_sum(array_column($second, 'provider_calls')), 'the provider was never reached');
        $this->assertSame(1, DB::table('ai_budget_reservations')->count(), 'nothing new was reserved');
    }

    public function test_clearing_the_cache_over_and_over_while_processes_reserve_neither_loses_nor_returns_budget(): void
    {
        $config = $this->live(['ai.limits.global.daily_requests' => 6, 'ai.rate_limits.vendor.per_minute' => 100000]);
        $worker = ['surface' => 'vendor', 'attempts' => 4, 'hold_ms' => 150, 'config' => $config];

        $results = $this->raceMixed([
            ['live', $worker], ['live', $worker], ['live', $worker], ['live', $worker], ['live', $worker], ['live', $worker],
            ['flush', ['duration_ms' => 4000, 'config' => $config]], // wipes the whole cache every few milliseconds
        ]);

        $flushed = array_sum(array_column($results, 'flushes'));
        $this->assertGreaterThan(20, $flushed, 'the cache really was being cleared during the race');

        $tally = $this->tally($results);
        $this->assertSame(6, $tally['ok'] ?? 0, 'exactly the budgeted six requests were served: '.json_encode($tally));
        $this->assertSame(6, $this->usage()['global']['requests'], 'and all six are still counted');
        $this->assertSame(6, DB::table('ai_budget_reservations')->count());
        $this->assertSame(6, array_sum(array_column($results, 'provider_calls')), 'the provider was reached only by admitted requests');
        $this->assertLedgerConsistent();

        Cache::flush();
        $after = $this->race('live', 1, ['surface' => 'vendor', 'attempts' => 1, 'config' => $config]);
        $this->assertSame(['ai_budget_exhausted' => 1], $this->tally($after), 'a final clear gave nothing back');
    }

    public function test_global_and_surface_limits_hold_together_when_two_surfaces_race(): void
    {
        $e = $this->estimate();
        $config = $this->base([
            'ai.limits.global.daily_tokens' => 5 * $e + 5,
            'ai.limits.surfaces.vendor.daily_tokens' => 3 * $e + 5,
            'ai.limits.surfaces.customer.daily_tokens' => 3 * $e + 5,
        ]);

        $vendor = ['surface' => 'vendor', 'attempts' => 3, 'unknown_usage' => true, 'config' => $config];
        $customer = ['surface' => 'customer', 'attempts' => 3, 'unknown_usage' => true, 'config' => $config];

        $results = $this->raceMixed([['engine', $vendor], ['engine', $vendor], ['engine', $vendor], ['engine', $vendor], ['engine', $customer], ['engine', $customer], ['engine', $customer], ['engine', $customer]]);

        $tally = $this->tally($results);
        $u = $this->usage();
        $this->assertSame(5, $tally['ok'] ?? 0, 'the GLOBAL budget binds: '.json_encode($tally));
        $this->assertSame(5 * $e, $u['global']['tokens']);
        $this->assertLessThanOrEqual(3, $u['surface.vendor']['requests'], 'each SURFACE limit binds too');
        $this->assertLessThanOrEqual(3, $u['surface.customer']['requests']);
        $this->assertSame($u['global']['requests'], $u['surface.vendor']['requests'] + $u['surface.customer']['requests']);
        $this->assertLedgerConsistent();
    }

    public function test_the_same_reservation_settled_by_many_processes_at_once_is_applied_exactly_once(): void
    {
        $guard = app(BudgetGuard::class);
        $r = $guard->reserve(AiSurface::Vendor, 1000, false, 400);

        $results = $this->race('settle', 8, ['id' => $r->id, 'day' => $r->day, 'surface' => 'vendor', 'tokens' => 1000, 'cost' => 400, 'config' => $this->base()]);

        $this->assertSame(['ok' => 8], $this->tally($results), 'a duplicate settlement is a harmless no-op, not an error');
        $u = $this->usage();
        $this->assertContains($u['global']['tokens'], [10, 20, 30, 40, 50, 60, 70, 80], 'exactly ONE worker\'s figure was applied: '.$u['global']['tokens']);
        $this->assertSame(1, $u['global']['successes'], 'counted as one success');
        $this->assertSame($u['global']['tokens'], $u['surface.vendor']['tokens']);
        $this->assertSame(BudgetGuard::SETTLED, DB::table('ai_budget_reservations')->value('state'));
        $this->assertLedgerConsistent();
    }

    public function test_concurrent_retries_each_take_their_own_reservation_and_never_exceed_the_cap(): void
    {
        // Every first attempt fails as certainly-not-sent, so the retry runs: each request makes TWO reservations.
        $config = $this->live(['ai.request.max_retries' => 1, 'ai.limits.global.daily_requests' => 10]);

        $results = $this->race('live', 6, ['surface' => 'vendor', 'attempts' => 2, 'first_unsent_failure' => true, 'config' => $config]);

        $this->assertLessThanOrEqual(10, $this->usage()['global']['requests'], 'attempts, not requests, are what the cap counts');
        $this->assertSame($this->usage()['global']['requests'], DB::table('ai_budget_reservations')->count(), 'one ledger row per attempt');
        $this->assertGreaterThan(0, DB::table('ai_budget_reservations')->whereNotNull('retry_of')->count(), 'retries were reserved independently and linked');
        $this->assertSame(0, DB::table('ai_budget_reservations')->where('state', BudgetGuard::OPEN)->count(), 'nothing was left open');
        $this->assertLedgerConsistent(json_encode($this->tally($results)));
    }

    // ------------------------------------------------------------------ emergency shutdown across real process restarts

    public function test_a_shutdown_survives_cache_clears_and_fresh_processes_until_it_is_released_on_purpose(): void
    {
        $config = $this->base();
        $this->assertSame(['ok' => 1], $this->tally($this->race('engine', 1, ['surface' => 'vendor', 'attempts' => 1, 'config' => $config])), 'running');

        app(KillSwitch::class)->engage('multi-process test');
        Cache::flush();
        Artisan::call('cache:clear');
        $usageBefore = $this->usage();

        // Brand-new PHP processes (a restart): every one of them must find AI stopped.
        $stopped = $this->race('killswitch', 4, ['config' => $config]);
        $this->assertSame(['engaged' => 4], $this->tally($stopped));

        $refused = $this->race('engine', 3, ['surface' => 'vendor', 'attempts' => 3, 'config' => $config]);
        $this->assertSame(['ai_disabled' => 9], $this->tally($refused));
        $this->assertSame(0, array_sum(array_map(fn ($r) => count($r['intervals']), $refused)), 'no provider call was made');
        $this->assertSame($usageBefore, $this->usage(), 'and no budget was reserved');

        Cache::flush(); // once more, after everything
        $this->assertSame(['engaged' => 1], $this->tally($this->race('killswitch', 1, ['config' => $config])));

        app(KillSwitch::class)->release('multi-process test over');
        $this->assertSame(['released' => 2], $this->tally($this->race('killswitch', 2, ['config' => $config])));
        $this->assertSame(['ok' => 1], $this->tally($this->race('engine', 1, ['surface' => 'vendor', 'attempts' => 1, 'config' => $config])));
    }

    public function test_a_shutdown_during_a_race_stops_new_work_and_leaves_no_reservation_open(): void
    {
        $config = $this->live();
        $worker = ['surface' => 'vendor', 'attempts' => 12, 'hold_ms' => 60, 'config' => $config];

        $results = $this->raceMixed([
            ['live', $worker], ['live', $worker], ['live', $worker], ['live', $worker],
            ['engager', ['delay_ms' => 400, 'config' => $config]],
        ]);

        $tally = $this->tally($results);
        $this->assertTrue(app(KillSwitch::class)->engaged());
        $this->assertGreaterThan(0, $tally['ok'] ?? 0, 'work was running before the shutdown: '.json_encode($tally));
        $this->assertGreaterThan(0, $tally['ai_disabled'] ?? 0, 'and was refused after it: '.json_encode($tally));
        $this->assertSame(['ai_disabled', 'engaged', 'ok'], array_keys(array_diff_key($tally, ['ai_busy' => 1, 'ai_unavailable' => 1])), json_encode($tally));
        $this->assertSame($tally['ok'], array_sum(array_column($results, 'provider_calls')), 'every provider call belongs to a request that was served');
        $this->assertSame(0, DB::table('ai_budget_reservations')->where('state', BudgetGuard::OPEN)->count(), 'nothing was left reserved by the shutdown');
        $this->assertLedgerConsistent();
    }
    // ------------------------------------------------------------------ the worker slot of a whole turn

    public function test_a_turn_slot_cap_holds_across_processes(): void
    {
        $this->requiresDatabaseCache();
        $results = $this->race('turn', 8, ['hold_ms' => 900, 'config' => $this->base(['ai.concurrency.max_in_flight' => 3])]);

        $tally = $this->tally($results);
        $this->assertSame(3, $tally['ok'] ?? 0, json_encode($tally));
        $this->assertSame(5, $tally['ai_busy'] ?? 0, json_encode($tally));
        $this->assertLessThanOrEqual(3, $this->peakOverlap($results), 'never more than max_in_flight turns at once');
    }

    public function test_a_turn_slot_is_free_again_once_its_turn_has_finished(): void
    {
        $this->requiresDatabaseCache();
        $config = $this->base(['ai.concurrency.max_in_flight' => 1]);

        $this->assertSame(['ok' => 1], $this->tally($this->race('turn', 1, ['hold_ms' => 100, 'config' => $config])));
        $this->assertSame(['ok' => 1], $this->tally($this->race('turn', 1, ['hold_ms' => 100, 'config' => $config])), 'the slot was released by the first turn');
    }
}
