<?php

/**
 * Child process for the opt-in multi-process AI engine tests (AiMultiProcessTest).
 *
 * Boots the application against the SAME MySQL/MariaDB database as the test process and uses the DATABASE cache store
 * (the production default), so counters, rate-limit keys and concurrency/probe locks are genuinely shared between
 * separate PHP processes. The offline mock provider is the only provider: no HTTP is ever sent (the worker asserts it).
 *
 *   php worker.php <mode> '<json args>'
 *
 * Every worker boots, signals "ready", waits for the "go" file (so all of them start together regardless of how long
 * each took to boot), does its work and prints exactly one JSON line.
 */

use App\Services\Ai\AiOrchestrator;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\AiMessage;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiResponse;
use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Data\AiUsage;
use App\Services\Ai\Exceptions\AiCircuitOpenException;
use App\Services\Ai\Exceptions\AiException;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Providers\AiProviderManager;
use App\Services\Ai\Providers\MockAiProvider;
use App\Services\Ai\Safety\CircuitBreaker;
use App\Services\Ai\Safety\ConcurrencyGuard;
use App\Services\Ai\Safety\KillSwitch;
use App\Services\Ai\Usage\BudgetGuard;
use App\Services\Ai\Usage\BudgetReservation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Ai\Support\FakeLiveProvider;

$base = dirname(__DIR__, 4);
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? '';
$args = json_decode($argv[2] ?? '{}', true) ?: [];

// Before anything touches the cache: the shared database store, plus the scenario's settings.
config(array_merge(['cache.default' => env('CACHE_STORE', 'database')], $args['config'] ?? []));
if (config('database.default') === 'sqlite') {
    // A file-backed SQLite database shared by several processes: wait for the writer instead of failing at once.
    config(['database.connections.sqlite.busy_timeout' => 30000, 'database.connections.sqlite.journal_mode' => 'wal']);
    DB::purge();
}
Http::fake();

// M5.7.2 fault injection (opt-in per scenario): an anchor that is unreachable for reservations, or a database that fails right
// AFTER the anchor accepted a reservation. Both must leave the provider unreached.
if (! empty($args['fault']['anchor_reserve'])) {
    $faulty = new \Tests\Feature\Ai\Support\FaultyAnchor(new \App\Services\Ai\Integrity\FileIntegrityAnchor((string) config('ai.integrity.anchor.path'), (string) config('ai.integrity.anchor.key')));
    $faulty->failBefore['reserve'] = true;
    $app->instance(\App\Services\Ai\Integrity\IntegrityAnchor::class, $faulty);
}
if (! empty($args['fault']['db_after_anchor'])) {
    DB::listen(function ($query): void {
        if (str_contains(str_replace(['"', '`'], '', $query->sql), 'insert into ai_budget_reservations')) {
            throw new RuntimeException('simulated database failure');
        }
    });
}

$out = ['mode' => $mode, 'pid' => getmypid(), 'outcomes' => [], 'intervals' => []];

$barrier = static function () use ($args): void {
    file_put_contents($args['ready'], (string) getmypid());
    $deadline = microtime(true) + 90;
    while (! file_exists($args['go'])) {
        if (microtime(true) > $deadline) {
            fwrite(STDERR, "go-file never appeared\n");
            exit(3);
        }
        usleep(300);
    }
};

switch ($mode) {
    // N attempts through the REAL orchestrator (all gates, budgets, locks) against the offline mock.
    case 'engine':
        $mock = $app->make(MockAiProvider::class);
        $hold = (int) ($args['hold_ms'] ?? 0);
        $unknown = (bool) ($args['unknown_usage'] ?? false);

        for ($i = 0; $i < (int) $args['attempts']; $i++) {
            $mock->queue(function () use (&$out, $hold, $unknown) {
                $start = microtime(true);
                if ($hold > 0) {
                    usleep($hold * 1000);
                }
                $out['intervals'][] = [$start, microtime(true)];

                return new AiResponse('ok', 'mock', 'mock-model', $unknown ? new AiUsage(null, null) : new AiUsage(10, 5));
            });
        }

        $engine = $app->make(AiOrchestrator::class);
        $surface = AiSurface::from($args['surface']);

        $barrier();

        for ($i = 0; $i < (int) $args['attempts']; $i++) {
            $principal = $args['shared_principal'] ?? ('worker:'.getmypid().':'.$i);
            try {
                $engine->run(new AiRequest($surface, $principal, [AiMessage::user($args['text'] ?? 'Hello there')]));
                $out['outcomes'][] = 'ok';
            } catch (AiException $e) {
                $out['outcomes'][] = $e->errorCode();
                if ($e->errorCode() === 'ai_misconfigured') {
                    $out['errors'][] = $e->getMessage(); // class names only: shows WHY a request failed closed
                }
            } catch (Throwable $e) {
                $out['outcomes'][] = 'UNEXPECTED:'.$e::class;
            }
        }
        break;

        // N attempts through the REAL orchestrator with a LIVE-flagged provider (no network: FakeLiveProvider), so the
        // strict hard-budget rules (finite request/token/COST limits, reserved upper bound) are the ones being raced.
    case 'live':
        $live = new FakeLiveProvider;
        $hold = (int) ($args['hold_ms'] ?? 0);
        $unknown = (bool) ($args['unknown_usage'] ?? false);

        for ($i = 0; $i < (int) $args['attempts']; $i++) {
            if (! empty($args['first_unsent_failure'])) {
                // The first provider call of a request provably never left this server, so the engine retries it.
                $live->queue(AiProviderException::unavailable('refused', requestSent: false));
            }

            $live->queue(function () use (&$out, $hold, $unknown) {
                $start = microtime(true);
                if ($hold > 0) {
                    usleep($hold * 1000);
                }
                $out['intervals'][] = [$start, microtime(true)];

                return new AiResponse('ok', 'fakelive', 'test-model', $unknown ? new AiUsage(null, null) : new AiUsage(10, 5));
            });
        }

        $app->instance(AiProviderManager::class, new class($live) extends AiProviderManager
        {
            public function __construct(private readonly AiProvider $fixed) {}

            public function provider(): AiProvider
            {
                return $this->fixed;
            }
        });

        $engine = $app->make(AiOrchestrator::class);
        $surface = AiSurface::from($args['surface']);

        $barrier();

        for ($i = 0; $i < (int) $args['attempts']; $i++) {
            try {
                $engine->run(new AiRequest($surface, 'worker:'.getmypid().':'.$i, [AiMessage::user($args['text'] ?? 'Hello there')]));
                $out['outcomes'][] = 'ok';
            } catch (AiException $e) {
                $out['outcomes'][] = $e->errorCode();
                if ($e->errorCode() === 'ai_misconfigured') {
                    $out['errors'][] = $e->getMessage(); // class names only: shows WHY a request failed closed
                }
            } catch (Throwable $e) {
                $out['outcomes'][] = 'UNEXPECTED:'.$e::class;
            }
        }
        $out['provider_calls'] = $live->calls;
        break;

        // One whole assistant TURN's worker slot, raced by many processes: at most max_in_flight may hold one at a time.
    case 'turn':
        $guard = $app->make(ConcurrencyGuard::class);
        $hold = (int) ($args['hold_ms'] ?? 600);

        $barrier();

        try {
            $guard->turn(function () use (&$out, $hold) {
                $start = microtime(true);
                usleep($hold * 1000);
                $out['intervals'][] = [$start, microtime(true)];
            });
            $out['outcomes'][] = 'ok';
        } catch (AiException $e) {
            $out['outcomes'][] = $e->errorCode();
        }
        break;

        // The half-open circuit: everyone asks for a permit at the same instant; whoever gets the probe holds it a while.
    case 'probe':
        $breaker = $app->make(CircuitBreaker::class);
        $barrier();
        try {
            $permit = $breaker->assertAllows('mock');
            $out['outcomes'][] = $permit->isProbe ? 'probe' : 'closed';
            usleep((int) ($args['hold_ms'] ?? 800) * 1000);
            $breaker->recordSuccess($permit);
        } catch (AiCircuitOpenException) {
            $out['outcomes'][] = 'open';
        }
        break;

        // Durable-state scenarios (M5.7.1).
        // Clears the WHOLE shared cache again and again while the other processes are reserving budget.
    case 'flush':
        $barrier();
        $until = microtime(true) + ((int) $args['duration_ms']) / 1000;
        $flushes = 0;
        while (microtime(true) < $until) {
            Cache::flush();
            $flushes++;
            usleep(3000);
        }
        $out['flushes'] = $flushes;
        break;

        // Everyone settles the SAME reservation at the same instant, each with its own numbers: exactly one may win.
    case 'settle':
        $guard = $app->make(BudgetGuard::class);
        $reservation = new BudgetReservation($args['id'], $args['day'], AiSurface::from($args['surface']), (int) $args['tokens'], (int) $args['cost']);
        $barrier();
        try {
            $guard->settle($reservation, ((int) $args['index'] + 1) * 10, null);
            $out['outcomes'][] = 'ok';
        } catch (Throwable $e) {
            $out['outcomes'][] = 'UNEXPECTED:'.$e::class;
        }
        break;

        // What a freshly started process sees of the emergency stop.
    case 'killswitch':
        $switch = $app->make(KillSwitch::class);
        $barrier();
        $out['outcomes'][] = $switch->engaged() ? 'engaged' : 'released';
        break;

        // An operator hitting the emergency stop while the others are busy.
    case 'engager':
        $barrier();
        usleep((int) ($args['delay_ms'] ?? 100) * 1000);
        $app->make(KillSwitch::class)->engage('race test');
        $out['outcomes'][] = 'engaged';
        break;

    default:
        $out['error'] = 'unknown mode';
}

$out['http_sent'] = Http::recorded()->count();

echo json_encode($out).PHP_EOL;
