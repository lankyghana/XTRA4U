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
use App\Services\Ai\Data\AiMessage;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiResponse;
use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Data\AiUsage;
use App\Services\Ai\Exceptions\AiCircuitOpenException;
use App\Services\Ai\Exceptions\AiException;
use App\Services\Ai\Providers\MockAiProvider;
use App\Services\Ai\Safety\CircuitBreaker;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

$base = dirname(__DIR__, 4);
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? '';
$args = json_decode($argv[2] ?? '{}', true) ?: [];

// Before anything touches the cache: the shared database store, plus the scenario's settings.
config(array_merge(['cache.default' => 'database'], $args['config'] ?? []));
Http::fake();

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
            } catch (Throwable $e) {
                $out['outcomes'][] = 'UNEXPECTED:'.$e::class;
            }
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

    default:
        $out['error'] = 'unknown mode';
}

$out['http_sent'] = Http::recorded()->count();

echo json_encode($out).PHP_EOL;
