<?php

namespace Tests\Feature\Ai\MultiProcess;

use App\Services\Ai\Data\AiMessage;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Usage\TokenEstimator;
use App\Services\Ai\Usage\UsageMeter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\NullHandler;
use Tests\TestCase;

/**
 * REAL multi-process safety of the AI engine on the production cache store (the `database` driver), against
 * MySQL / MariaDB. Single-process tests cannot prove any of this: they simulate "a second request" by re-entering
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
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql' || ! env('AI_MULTIPROCESS_TESTS')) {
            $this->markTestSkipped('Multi-process AI tests are opt-in (AI_MULTIPROCESS_TESTS=1 on a MySQL/MariaDB connection).');
        }

        config(['cache.default' => 'database']);
        $this->resetSharedState();
    }

    protected function tearDown(): void
    {
        if (config('database.default') === 'mysql' && env('AI_MULTIPROCESS_TESTS')) {
            $this->resetSharedState();
        }

        parent::tearDown();
    }

    private function resetSharedState(): void
    {
        DB::table('cache')->truncate();
        DB::table('cache_locks')->truncate();
        Cache::flush();
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
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ai-mp-'.uniqid();
        mkdir($dir);
        $go = $dir.DIRECTORY_SEPARATOR.'go';

        $procs = [];
        for ($i = 0; $i < $count; $i++) {
            $ready = $dir.DIRECTORY_SEPARATOR."ready.{$i}";
            $cmd = [PHP_BINARY, __DIR__.'/worker.php', $mode, json_encode($args + ['ready' => $ready, 'go' => $go])];
            // CACHE_STORE must be 'database' from the very first line of the worker's boot, exactly as in production:
            // singletons built during bootstrap (the rate limiter among them) bind to the default store at that moment.
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), array_merge(getenv(), ['CACHE_STORE' => 'database']));
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
        Cache::put('ai:cb:mock:open_until', now()->getTimestamp() - 5, 86400); // cooldown already elapsed

        $results = $this->race('probe', 8, ['hold_ms' => 1000, 'config' => $this->base()]);

        $tally = $this->tally($results);
        $this->assertSame(1, $tally['probe'] ?? 0, json_encode($tally));
        $this->assertSame(7, $tally['open'] ?? 0, json_encode($tally));
    }
}
