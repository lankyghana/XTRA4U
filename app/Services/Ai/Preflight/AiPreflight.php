<?php

namespace App\Services\Ai\Preflight;

use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Integrity\IntegrityGuard;
use App\Services\Ai\Providers\MockAiProvider;
use App\Services\Ai\Providers\OpenAiProvider;
use App\Services\Ai\Safety\CacheEngineCheck;
use App\Services\Ai\Safety\ExternalProcessingGate;
use App\Services\Ai\Safety\KillSwitch;
use App\Services\Ai\Usage\BudgetGuard;
use App\Services\Ai\Usage\PriceTable;
use App\Services\Ai\Usage\UsageMeter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only evaluation of whether the AI engine is safely configured. It answers three different questions and keeps
 * them apart:
 *
 *   1. What state is the system in?    (disabled / mock / live-configured)
 *   2. Is that state safe?              (disabled and mock are safe by construction)
 *   3. Could a live provider be activated, in a SEPARATE, explicitly approved step?  (every automatic live gate passes)
 *
 * It never enables anything, never changes configuration, never sends a request of any kind (no provider, no network),
 * and never reveals a secret: it reports whether a value is present and well-formed, not what it is. Reads are limited
 * to configuration, table metadata and a few cache keys. It cannot know some facts (the web server's timeout, the real
 * proxy topology, legal approval) - those appear as MANUAL items or as operator attestations, never as a pass it cannot
 * justify, and the verdict never says "production ready".
 */
class AiPreflight
{
    public const PASS = 'pass';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    public const MANUAL = 'manual';

    public const INFO = 'info';

    /** @var list<array{id:string,status:string,live:bool,title:string,detail:string}> */
    private array $checks = [];

    public function __construct(
        private readonly KillSwitch $killSwitch,
        private readonly CacheEngineCheck $cache,
        private readonly ExternalProcessingGate $gate,
        private readonly BudgetGuard $budget,
        private readonly PriceTable $prices,
        private readonly UsageMeter $meter,
        private readonly OpenAiProvider $openai,
        private readonly IntegrityGuard $integrity,
    ) {}

    /**
     * @return array{state:string,verdict:string,live_ready:bool,failures:int,warnings:int,manual:int,checks:list<array{id:string,status:string,live:bool,title:string,detail:string}>}
     */
    public function run(): array
    {
        $this->checks = [];

        $provider = config('ai.provider');
        $enabledSurfaces = $this->enabledSurfaces();
        $engineOn = config('ai.enabled') === true && ! $this->killSwitchEngaged();

        $this->engineAndSurfaces($engineOn, $enabledSurfaces);
        $this->provider($provider);
        $this->privacy();
        $this->model();
        $this->budgets($enabledSurfaces);
        $this->integrity();
        $this->limitsAndTimeouts();
        $this->cache();
        $this->network($enabledSurfaces);
        $this->customerLaunch($enabledSurfaces);
        $this->manual();

        $failures = count(array_filter($this->checks, fn ($c) => $c['status'] === self::FAIL));
        $liveFailures = count(array_filter($this->checks, fn ($c) => $c['status'] === self::FAIL && $c['live']));

        return [
            'state' => $this->state($engineOn, $enabledSurfaces, $provider),
            'verdict' => $this->verdict($engineOn, $enabledSurfaces, $provider, $liveFailures),
            'live_ready' => $provider === OpenAiProvider::NAME && $liveFailures === 0,
            'failures' => $failures,
            'warnings' => count(array_filter($this->checks, fn ($c) => $c['status'] === self::WARN)),
            'manual' => count(array_filter($this->checks, fn ($c) => $c['status'] === self::MANUAL)),
            'checks' => $this->checks,
        ];
    }

    // ---------------------------------------------------------------------------------------------- verdicts

    /** @param list<AiSurface> $surfaces */
    private function state(bool $engineOn, array $surfaces, mixed $provider): string
    {
        if (! $engineOn || $surfaces === []) {
            return 'disabled';
        }

        return match ($provider) {
            MockAiProvider::NAME => 'mock',
            OpenAiProvider::NAME => 'live',
            default => 'misconfigured',
        };
    }

    /** @param list<AiSurface> $surfaces */
    private function verdict(bool $engineOn, array $surfaces, mixed $provider, int $liveFailures): string
    {
        $live = $provider === OpenAiProvider::NAME
            ? ($liveFailures === 0
                ? 'Live gates: every AUTOMATIC check passes. This is NOT production readiness and does not enable anything - manual items and a separate, explicit approval remain.'
                : "Live gates: BLOCKED ({$liveFailures} failing check".($liveFailures === 1 ? '' : 's').'): a live provider would refuse to run.')
            : 'Live gates: not in use (the provider is not "openai").';

        $state = match ($this->state($engineOn, $surfaces, $provider)) {
            'disabled' => 'SAFE: AI is disabled (the engine is off, stopped by the kill switch, or no surface is enabled). No assistant answers and no provider is called.',
            'mock' => 'CONFIGURED FOR MOCK OPERATION: enabled surfaces answer from the offline mock provider only. No external call is possible.',
            'live' => $liveFailures === 0
                ? 'LIVE PROVIDER CONFIGURED: automatic gates pass, so enabled surfaces CAN reach the external provider and spend money.'
                : 'LIVE PROVIDER SELECTED BUT BLOCKED: every request fails closed before reaching the provider.',
            default => 'MISCONFIGURED: the provider setting is not recognised; every request fails closed.',
        };

        return $state.' '.$live;
    }

    // ---------------------------------------------------------------------------------------------- checks

    /** @param list<AiSurface> $enabled */
    private function engineAndSurfaces(bool $engineOn, array $enabled): void
    {
        $this->add('engine', self::INFO, false, 'Global AI switch (AI_ENABLED)', config('ai.enabled') === true ? 'on' : 'off (default)');
        $status = $this->killSwitch->status();
        $this->add(
            'kill_switch',
            self::INFO,
            false,
            'Runtime kill switch (durable, database)',
            ! $status['readable']
                ? 'ENGAGED: the database state cannot be read, so AI fails closed'
                : ($status['engaged'] ? 'ENGAGED ('.($status['state'] ?? 'no state row').'): all AI is stopped until `php artisan ai:kill-switch release`' : 'released')
                    .($status['anchor']['configured'] ? ' | independent anchor: '.($status['anchor']['readable'] ? $status['anchor']['state'].' (generation '.$status['anchor']['generation'].')' : 'UNREADABLE') : ' | no independent anchor')
        );

        foreach (AiSurface::cases() as $surface) {
            $on = config("ai.surfaces.{$surface->value}.enabled") === true;
            $this->add("surface_{$surface->value}", self::INFO, false, "Surface flag: {$surface->value}", $on ? 'enabled' : 'disabled (default)');
        }

        $this->add(
            'surfaces_selected',
            $enabled === [] ? self::FAIL : self::PASS,
            true,
            'At least one surface is selected',
            $enabled === [] ? 'no surface flag is enabled - set the flag of each surface that is meant to go live (AI_ENABLED may stay off until the separate activation)' : implode(', ', array_map(fn ($s) => $s->value, $enabled))
        );
    }

    private function provider(mixed $provider): void
    {
        $label = is_string($provider) ? $provider : 'invalid';
        $this->add('provider', self::INFO, false, 'Provider selection (AI_PROVIDER)', $label.($provider === MockAiProvider::NAME ? ' (default)' : ''));

        $this->add(
            'provider_is_openai',
            $provider === OpenAiProvider::NAME ? self::PASS : self::FAIL,
            true,
            'Provider is the live adapter',
            $provider === OpenAiProvider::NAME ? 'openai' : 'a live-readiness check is only meaningful with AI_PROVIDER=openai'
        );

        $production = app()->environment('production');
        $mockAllowed = (bool) config('ai.providers.mock.allowed_in_production', false);
        $this->add(
            'mock_in_production',
            $provider === MockAiProvider::NAME && $production && $mockAllowed ? self::FAIL : self::PASS,
            true,
            'Mock provider restriction in production',
            $production
                ? ($mockAllowed ? 'AI_MOCK_IN_PRODUCTION is true: the mock may answer real users' : 'the mock provider refuses to run in production')
                : 'not a production environment'
        );

        $key = config('ai.providers.openai.api_key');
        $this->add(
            'api_key',
            is_string($key) && preg_match('/^[A-Za-z0-9._\-]{20,256}$/D', $key) === 1 ? self::PASS : self::FAIL,
            true,
            'Provider API key (AI_OPENAI_API_KEY)',
            is_string($key) && $key !== '' ? (preg_match('/^[A-Za-z0-9._\-]{20,256}$/D', $key) === 1 ? 'present, well-formed (value not shown)' : 'present but malformed (value not shown)') : 'not set'
        );
    }

    private function privacy(): void
    {
        $problems = $this->gate->problems();

        $this->add(
            'external_processing',
            $problems === [] ? self::PASS : self::FAIL,
            true,
            'External-processing approval record',
            $problems === []
                ? 'a complete approval record is configured (this is a record, not proof: confirm it matches the signed-off privacy review)'
                : implode('; ', $problems)
        );
    }

    private function model(): void
    {
        $model = config('ai.default_model');
        $ok = is_string($model) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\-]{0,99}$/D', $model) === 1;

        $this->add('model', $ok ? self::PASS : self::FAIL, true, 'Explicit model (AI_DEFAULT_MODEL)', $ok ? $model : 'not set: no default model is shipped');

        $pricing = $ok ? (array) config("ai.pricing.openai.{$model}", []) : [];
        $priced = $ok && $this->prices->maxCostMicroUsd(OpenAiProvider::NAME, $model, 1, 1) !== null;

        $this->add(
            'pricing',
            $priced ? self::PASS : self::FAIL,
            true,
            'Model pricing (USD per 1M tokens)',
            $priced
                ? 'configured'.(($pricing['as_of'] ?? '') !== '' ? ', as of '.$pricing['as_of'] : ' (AI_OPENAI_PRICING_AS_OF is not recorded)')
                : 'input and output prices for the model are not both configured (none are shipped or guessed)'
        );

        if ($priced && ($pricing['as_of'] ?? '') === '') {
            $this->add('pricing_date', self::WARN, false, 'Pricing date recorded', 'set AI_OPENAI_PRICING_AS_OF to the date the prices were copied from the provider');
        }
    }

    /** @param list<AiSurface> $enabled */
    private function budgets(array $enabled): void
    {
        $problems = [];

        foreach ($enabled as $surface) {
            foreach ($this->budget->liveLimitProblems($surface) as $p) {
                $problems[] = "{$surface->value}: {$p}";
            }
        }

        // The global scope is shared by every surface, so it is judged even when no surface is enabled.
        if ($enabled === []) {
            foreach ($this->budget->liveLimitProblems(AiSurface::Customer) as $p) {
                if (str_starts_with($p, '[global]')) {
                    $problems[] = $p;
                }
            }
        }

        $problems = array_values(array_unique($problems));

        $this->add(
            'budgets',
            $problems === [] ? self::PASS : self::FAIL,
            true,
            'Daily budgets (requests, tokens, cost) - global and each enabled surface',
            $problems === [] ? 'explicit positive limits are configured' : implode('; ', $problems)
        );

        // Is one worst-case request even affordable inside each cost/token limit?
        $maxCost = $this->worstCaseCost();
        $worstTokens = $this->worstCaseTokens();

        $this->add(
            'max_request_cost',
            $maxCost === null ? self::FAIL : self::INFO,
            $maxCost === null,
            'Maximum cost of ONE request (reserved before every call)',
            $maxCost === null ? 'unknown until model pricing is configured' : $maxCost.' micro-USD (= USD '.number_format($maxCost / 1_000_000, 6).'), up to '.$worstTokens.' tokens'
        );

        if ($maxCost !== null) {
            $tooSmall = [];
            foreach (['global' => 'ai.limits.global', ...array_combine(
                array_map(fn ($s) => $s->value, $enabled),
                array_map(fn ($s) => "ai.limits.surfaces.{$s->value}", $enabled)
            )] as $scope => $path) {
                $cost = config("{$path}.daily_cost_micro_usd");
                $tokens = config("{$path}.daily_tokens");
                if ((is_int($cost) && $cost > 0 && $cost < $maxCost) || (is_int($tokens) && $tokens > 0 && $tokens < $worstTokens)) {
                    $tooSmall[] = $scope;
                }
            }

            $this->add(
                'budget_fits_a_request',
                $tooSmall === [] ? self::PASS : self::FAIL,
                true,
                'Each daily limit can hold at least one worst-case request',
                $tooSmall === [] ? 'yes' : 'too small for a single request: '.implode(', ', $tooSmall)
            );
        }

        $this->add(
            'budget_integrity',
            $this->integrityFlagged() ? self::FAIL : self::PASS,
            true,
            'Budget integrity flag (a provider reported more usage than reserved today)',
            $this->integrityFlagged() ? 'FLAGGED: live execution is stopped for today - investigate ai.budget.bound_violated' : 'clear'
        );

        $this->durableStore();
    }

    /** The budget ledger and the kill-switch flag live in the database: they must exist, be transactional and agree. */
    private function durableStore(): void
    {
        $problems = [];

        try {
            foreach ([UsageMeter::COUNTERS_TABLE, UsageMeter::LEDGER_TABLE, UsageMeter::FLAGS_TABLE] as $table) {
                if (! Schema::hasTable($table)) {
                    $problems[] = "table [{$table}] is missing: run the migrations";

                    continue;
                }

                if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                    $engine = DB::selectOne('SELECT ENGINE AS e FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table])->e ?? null;

                    if (! is_string($engine) || strtoupper($engine) !== 'INNODB') {
                        $problems[] = "table [{$table}] uses engine [".(is_string($engine) ? $engine : 'unknown').'], not InnoDB: no row locks or transactions, so no atomic reservations';
                    }
                }
            }

            if ($problems === []) {
                foreach ($this->budget->integrityProblems() as $p) {
                    $problems[] = $p;
                }
            }
        } catch (\Throwable) {
            $problems[] = 'the budget ledger cannot be read from the database';
        }

        $this->add(
            'durable_budget_store',
            $problems === [] ? self::PASS : self::FAIL,
            true,
            'Durable budget ledger (database, InnoDB) is present and consistent',
            $problems === [] ? 'counters equal the ledger for today; state survives cache:clear and restarts' : implode('; ', $problems)
        );

        try {
            $stale = $this->budget->staleOpenReservations();
        } catch (\Throwable) {
            $stale = 0;
        }

        if ($stale > 0) {
            $this->add('budget_stale_reservations', self::WARN, false, 'Reservations still open after 15 minutes', "{$stale} reservation(s) were never settled (a process died mid-request). They stay charged in full, which is the safe direction; review them.");
        }
    }

    /**
     * M5.7.2: protection against the database moving BACKWARDS (a restore, a point-in-time recovery, truncated tables), which
     * no check inside the database can detect. Needs an independent anchor, an attestation that it really is independent, a
     * matching history, and an explicit statement that the provider-side spending cap exists. Nothing here contacts anything.
     */
    private function integrity(): void
    {
        $problems = $this->integrity->readinessProblems();

        $this->add(
            'integrity_anchor',
            $problems === [] ? self::PASS : self::FAIL,
            true,
            'Independent integrity anchor (outside the database restore domain)',
            $problems === []
                ? 'configured, authentic, bound to this database. INDEPENDENCE IS AN OPERATOR ATTESTATION (reference: '.(string) config('ai.integrity.anchor.independence_reference').'), not something this check can verify'
                : implode('; ', $problems).'. Without it a database restore can silently erase spent budget and re-enable AI: live execution stays blocked'
        );

        if ($problems === []) {
            $mismatch = $this->integrity->problems();

            $this->add(
                'integrity_consistency',
                $mismatch === [] ? self::PASS : self::FAIL,
                true,
                'Database budget history agrees with the anchor',
                $mismatch === [] ? 'every anchor reservation is in the ledger, nothing is lower, the sequences match' : implode('; ', array_slice($mismatch, 0, 5)).' - see `php artisan ai:integrity status` and docs/ai/06 section 19'
            );
        }

        $column = $this->integrity->schemaReady();

        $this->add('integrity_schema', $column ? self::PASS : self::FAIL, true, 'Ledger carries the anchor sequence (migration 2026_11_24_000001)', $column ? 'present' : 'missing: run the migrations');

        $cap = (bool) config('ai.operations.provider_spending_cap_confirmed', false);
        $ref = config('ai.operations.provider_spending_cap_reference');
        $capOk = $cap && is_string($ref) && strlen(trim($ref)) >= 3;

        $this->add(
            'provider_spending_cap',
            $capOk ? self::PASS : self::FAIL,
            true,
            'Provider-side spending cap is confirmed',
            $capOk
                ? 'an operator states a cap is configured at the provider (reference: '.$ref.'). This is a statement, not a verification: no provider API is called and no amount is recorded here'
                : 'not confirmed (AI_PROVIDER_SPENDING_CAP_CONFIRMED + AI_PROVIDER_SPENDING_CAP_REFERENCE). Local budgets are computed from configured prices and do not replace an independent limit set at the provider'
        );
    }

    private function limitsAndTimeouts(): void
    {
        $r = (array) config('ai.request', []);
        $ints = fn (string $k) => isset($r[$k]) && is_int($r[$k]) && $r[$k] >= 1;

        $bad = array_values(array_filter(
            ['max_request_bytes', 'max_context_messages', 'max_output_tokens', 'timeout_seconds', 'max_timeout_seconds', 'connect_timeout_seconds', 'deadline_seconds', 'turn_deadline_seconds'],
            fn ($k) => ! $ints($k)
        ));

        $this->add(
            'request_limits',
            $bad === [] && $r['max_output_tokens'] >= OpenAiProvider::MIN_OUTPUT_TOKENS ? self::PASS : self::FAIL,
            true,
            'Request size and output limits',
            $bad === []
                ? "max_request_bytes={$r['max_request_bytes']}, max_context_messages={$r['max_context_messages']}, max_output_tokens={$r['max_output_tokens']}".($r['max_output_tokens'] < OpenAiProvider::MIN_OUTPUT_TOKENS ? ' (below the provider minimum of '.OpenAiProvider::MIN_OUTPUT_TOKENS.')' : '')
                : 'missing or invalid: '.implode(', ', $bad)
        );

        $nest = $bad === [] && $r['timeout_seconds'] <= $r['max_timeout_seconds']
            && $r['max_timeout_seconds'] <= $r['deadline_seconds']
            && $r['deadline_seconds'] <= $r['turn_deadline_seconds']
            && $r['connect_timeout_seconds'] <= $r['timeout_seconds'];

        $this->add(
            'timeouts',
            $nest ? self::PASS : self::FAIL,
            true,
            'Timeouts nest: connect <= attempt <= max attempt <= provider round <= whole turn',
            $nest
                ? "connect {$r['connect_timeout_seconds']}s <= attempt {$r['timeout_seconds']}s <= max {$r['max_timeout_seconds']}s <= round {$r['deadline_seconds']}s <= turn {$r['turn_deadline_seconds']}s"
                : 'the configured timeouts do not fit inside one another'
        );

        $limitsOk = true;
        foreach (AiSurface::cases() as $surface) {
            $v = config("ai.rate_limits.{$surface->value}.per_minute");
            $limitsOk = $limitsOk && is_int($v) && $v >= 1;
        }
        $this->add('rate_limits', $limitsOk ? self::PASS : self::FAIL, true, 'Per-principal rate limits (all surfaces)', $limitsOk ? 'valid' : 'a surface has no valid per-minute limit');

        $max = config('ai.concurrency.max_in_flight');
        $lock = config('ai.concurrency.lock_seconds');
        $this->add(
            'concurrency',
            is_int($max) && $max >= 1 && is_int($lock) && $lock >= 5 ? self::PASS : self::FAIL,
            true,
            'Concurrency cap (turns in flight across all processes)',
            is_int($max) && is_int($lock) ? "max_in_flight={$max}, lock_seconds={$lock}" : 'invalid'
        );

        $cb = (array) config('ai.circuit_breaker', []);
        $cbOk = ! array_filter(['failure_threshold', 'window_seconds', 'cooldown_seconds'], fn ($k) => ! (isset($cb[$k]) && is_int($cb[$k]) && $cb[$k] >= 1));
        $this->add('circuit_breaker', $cbOk ? self::PASS : self::FAIL, true, 'Circuit breaker settings', $cbOk ? 'valid' : 'invalid');
    }

    private function cache(): void
    {
        $r = $this->cache->inspect();

        $this->add(
            'cache_driver',
            $r['suitable'] ? self::PASS : self::FAIL,
            true,
            "Shared cache ({$r['store_driver']})",
            $r['summary']
        );

        foreach ($r['tables'] as $t) {
            $this->add(
                'cache_table_'.$t['table'],
                $t['suitable'] ? self::PASS : self::FAIL,
                true,
                "Cache table [{$t['table']}] on connection [{$t['connection']}]",
                ($t['exists'] === false ? 'MISSING. ' : '').($t['engine'] !== null ? "engine={$t['engine']}. " : '').$t['note']
            );
        }
    }

    /** @param list<AiSurface> $enabled */
    private function network(array $enabled): void
    {
        $configured = array_values((array) config('trustedproxy.proxies', []));
        $rawRejected = (int) config('trustedproxy.rejected', 0);
        $direct = (bool) config('ai.operations.direct_connections_confirmed', false);
        $publicSurface = in_array(AiSurface::Customer, $enabled, true);

        $this->add(
            'trusted_proxies',
            $rawRejected > 0 ? self::FAIL : self::INFO,
            $rawRejected > 0,
            'Trusted proxies (TRUSTED_PROXIES)',
            $configured === []
                ? 'none configured: forwarded headers are never trusted; the connecting address is the client'
                : count($configured).' trusted proxy entr'.(count($configured) === 1 ? 'y' : 'ies').' configured (addresses not shown)'
        );

        if ($rawRejected > 0) {
            $this->add('trusted_proxies_rejected', self::FAIL, true, 'Invalid TRUSTED_PROXIES entries', "{$rawRejected} entr".($rawRejected === 1 ? 'y was' : 'ies were').' rejected and ignored (wildcards, hostnames and ranges wider than /8 (IPv4) or /16 (IPv6) are never accepted)');
        }

        $decided = $configured !== [] || $direct;
        $this->add(
            'proxy_topology',
            $decided ? self::PASS : ($publicSurface ? self::FAIL : self::WARN),
            $publicSurface,
            'Client-address source for per-IP limits is decided',
            $decided
                ? ($configured !== [] ? 'behind proxies listed in TRUSTED_PROXIES' : 'operator confirmed direct connections (AI_DIRECT_CONNECTIONS_CONFIRMED)')
                : 'neither TRUSTED_PROXIES nor AI_DIRECT_CONNECTIONS_CONFIRMED is set. Behind an untrusted proxy every visitor shares ONE per-IP bucket; the production proxy addresses have not been supplied'
        );
    }

    /** @param list<AiSurface> $enabled */
    private function customerLaunch(array $enabled): void
    {
        $customer = in_array(AiSurface::Customer, $enabled, true);
        $confirmed = (bool) config('ai.operations.customer_worker_capacity_confirmed', false);

        $this->add(
            'customer_launch',
            self::INFO,
            false,
            'Customer Assistant (public) surface',
            $customer ? 'ENABLED' : 'disabled (default)'
        );

        $this->add(
            'customer_worker_capacity',
            $confirmed ? self::PASS : ($customer ? self::FAIL : self::WARN),
            $customer,
            'Worker capacity for the public synchronous assistant is confirmed',
            $confirmed
                ? 'operator confirmed (AI_CUSTOMER_WORKER_CAPACITY_CONFIRMED): '.config('ai.concurrency.max_in_flight').' turn(s) may hold a worker for up to '.config('ai.request.turn_deadline_seconds').'s each'
                : 'not confirmed. The assistant is synchronous: each in-flight turn holds a PHP worker for up to '.config('ai.request.turn_deadline_seconds').'s. Keep the customer surface disabled until capacity is verified'
        );
    }

    private function manual(): void
    {
        $this->add('manual_timeout', self::MANUAL, false, 'Web-server / PHP-FPM request timeout', 'cannot be read from here: confirm it is longer than ai.request.turn_deadline_seconds (+ margin) on the production host');
        $this->add('manual_legal', self::MANUAL, false, 'Legal / privacy sign-off', 'the approval record above is not proof: confirm the signed-off privacy review, DPA and provider data-handling terms exist');
        $this->add('manual_spend', self::MANUAL, false, 'Explicit spending approval', 'confirm the daily limits above were approved by the person accountable for the spend');
        $this->add('manual_cache_controls', self::MANUAL, false, 'Cache-based safety controls (rate limits, concurrency slots, circuit breaker)', 'they reset when the cache is cleared or restarted and fail closed (the request is refused before the provider) when the cache is unavailable. After a clear, up to 2x max_in_flight turns can briefly overlap: it bounds capacity, never spend. Treat a cache flush during live operation as a capacity incident (docs/ai/06 section 19)');
        $this->add('manual_cache_clear', self::MANUAL, false, 'Cache clears are safe for the budget, not for the rate limits', 'budget counters and the kill switch are durable (database) and survive `php artisan cache:clear`; the per-minute rate limits, concurrency slots and circuit breaker are cache-based and DO reset, so avoid clearing the cache at peak');
    }

    // ------------------------------------------------------------------------------------------------ helpers

    private function add(string $id, string $status, bool $live, string $title, string $detail): void
    {
        $this->checks[] = ['id' => $id, 'status' => $status, 'live' => $live, 'title' => $title, 'detail' => $detail];
    }

    /** @return list<AiSurface> */
    private function enabledSurfaces(): array
    {
        return array_values(array_filter(AiSurface::cases(), fn ($s) => config("ai.surfaces.{$s->value}.enabled") === true));
    }

    private function killSwitchEngaged(): bool
    {
        return $this->killSwitch->engaged();
    }

    private function integrityFlagged(): bool
    {
        try {
            return $this->meter->integrityFlagged($this->meter->today());
        } catch (\Throwable) {
            return true;
        }
    }

    private function worstCaseTokens(): int
    {
        $b = (array) config('ai.budget', []);
        $request = (int) config('ai.request.max_request_bytes', 0);
        $messages = (int) config('ai.request.max_context_messages', 0);

        return max(1, (int) ($b['tool_schema_multiplier'] ?? 2)) * $request
            + (int) ($b['per_message_overhead_tokens'] ?? 16) * $messages
            + (int) ($b['fixed_overhead_tokens'] ?? 512)
            + (int) config('ai.request.max_output_tokens', 0);
    }

    private function worstCaseCost(): ?int
    {
        $model = config('ai.default_model');

        if (! is_string($model) || $model === '') {
            return null;
        }

        $output = (int) config('ai.request.max_output_tokens', 0);

        return $this->prices->maxCostMicroUsd(OpenAiProvider::NAME, $model, $this->worstCaseTokens() - $output, $output);
    }
}
