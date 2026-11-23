<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Data\AiMessage;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiResponse;
use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Data\AiUsage;
use App\Services\Ai\Exceptions\AiBudgetExceededException;
use App\Services\Ai\Exceptions\AiConfigurationException;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Usage\BudgetGuard;
use App\Services\Ai\Usage\PriceTable;
use App\Services\Ai\Usage\TokenEstimator;
use App\Services\Ai\Usage\UsageMeter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Ai\Support\FakeLiveProvider;

/**
 * M5.7: strict budget enforcement. What is reserved is an UPPER BOUND (input bound + output cap, and the maximum cost of
 * those tokens) on the global AND surface scope, atomically, BEFORE the provider is reached; it can only be lowered
 * toward the provider's reported usage, never below what may have been spent.
 */
class StrictBudgetTest extends AiTestCase
{
    private const SURFACE_SCOPE = 'surface.vendor';

    /** @return array{0:int,1:int,2:int,3:int} input bound, output bound, total tokens, maximum cost (micro-USD) for the standard request */
    private function bounds(string $text = 'Hello'): array
    {
        $this->liveBudgets(); // pricing for the fake live provider must exist before a maximum cost can be computed
        $r = $this->req($text)->resolved('test-model', 512, 15);
        $e = app(TokenEstimator::class);
        $in = $e->inputUpperBound($r);
        $out = $e->outputUpperBound($r);

        return [$in, $out, $in + $out, (int) app(PriceTable::class)->maxCostMicroUsd('fakelive', 'test-model', $in, $out)];
    }

    private function live(array $overrides = []): FakeLiveProvider
    {
        $live = $this->useLiveProvider();
        $this->liveBudgets($overrides);

        return $live;
    }

    // ------------------------------------------------------------------------------------------- reservation

    public function test_the_provider_is_reached_only_after_requests_tokens_input_output_and_cost_are_reserved_on_both_scopes(): void
    {
        [$in, $out, $total, $cost] = $this->bounds();
        $seen = [];
        $live = $this->live();
        $live->queue(function () use (&$seen) {
            $seen = $this->usage();

            return new AiResponse('ok', 'fakelive', 'test-model', new AiUsage(100, 50));
        });

        $this->engine()->run($this->req());

        $this->assertSame($in + $out, $total);
        foreach (['global', self::SURFACE_SCOPE] as $scope) {
            $this->assertSame(1, $seen[$scope]['requests'], "{$scope}: the request is counted before the call");
            $this->assertSame($total, $seen[$scope]['tokens'], "{$scope}: input bound + output cap reserved before the call");
            $this->assertSame($cost, $seen[$scope]['cost_micro_usd'], "{$scope}: maximum cost reserved before the call");
        }
        $this->assertSame(1, $live->calls);
    }

    public function test_the_input_bound_covers_every_message_and_every_tool_definition_with_margin(): void
    {
        $messages = [
            AiMessage::system(str_repeat('s', 300)),
            AiMessage::user(str_repeat('é', 100)),   // 2 bytes per character
            AiMessage::assistant('a'),
        ];
        $tools = [['name' => 'x', 'description' => 'd', 'parameters' => ['type' => 'object']]];
        $r = new AiRequest(AiSurface::Vendor, 'p', $messages, 'm', 512, 15, $tools);

        $bytes = 300 + 200 + 1;
        $toolBytes = strlen((string) json_encode($tools));
        $bound = app(TokenEstimator::class)->inputUpperBound($r);

        // A byte-level tokenizer cannot produce more tokens than bytes; tool schemas get a x2 margin, plus overheads.
        $this->assertSame($bytes + 2 * $toolBytes + 3 * 16 + 512, $bound);
        $this->assertGreaterThanOrEqual($bytes + $toolBytes, $bound);
        $this->assertSame(512, app(TokenEstimator::class)->outputUpperBound($r));
    }

    public function test_a_limit_equal_to_the_reservation_admits_exactly_one_request_and_one_less_admits_none(): void
    {
        [, , $total, $cost] = $this->bounds();

        foreach ([
            'daily_tokens' => $total,
            'daily_cost_micro_usd' => $cost,
            'daily_requests' => 1,
        ] as $key => $exact) {
            $this->resetUsage();
            $live = $this->live(['ai.limits.surfaces.vendor.'.$key => $exact]);
            // The request settles to its (smaller) reported usage, so a second one only fits if there is room for ANOTHER bound.
            $this->engine()->run($this->req());
            $this->assertSame(1, $live->calls, "exact {$key} admits the request");
        }

        foreach ([
            'daily_tokens' => $total - 1,
            'daily_cost_micro_usd' => $cost - 1,
        ] as $key => $short) {
            $this->resetUsage();
            $live = $this->live(['ai.limits.surfaces.vendor.'.$key => $short]);

            try {
                $this->engine()->run($this->req());
                $this->fail("one unit short on {$key} must refuse");
            } catch (AiBudgetExceededException) {
            }

            $this->assertSame(0, $live->calls, "{$key}: the provider is never reached");
            $this->assertSame(0, $this->usage()['global']['tokens'], 'the refused reservation left nothing behind');
            $this->assertSame(0, $this->usage()['global']['cost_micro_usd']);
            $this->assertSame(0, $this->usage()['global']['requests']);
        }
    }

    public function test_a_global_limit_is_enforced_even_when_the_surface_has_room_and_nothing_lingers_on_either_scope(): void
    {
        [, , $total] = $this->bounds();
        $live = $this->live(['ai.limits.global.daily_tokens' => $total - 1]);

        $this->expectException(AiBudgetExceededException::class);
        try {
            $this->engine()->run($this->req());
        } finally {
            $this->assertSame(0, $live->calls);
            $this->assertSame(0, $this->usage()[self::SURFACE_SCOPE]['tokens'], 'the surface increment made before the global refusal was rolled back');
            $this->assertSame(0, $this->usage()[self::SURFACE_SCOPE]['requests']);
            $this->assertSame(0, $this->usage()[self::SURFACE_SCOPE]['cost_micro_usd']);
        }
    }

    public function test_a_surface_limit_refuses_after_the_global_scope_was_already_charged_and_rolls_it_back(): void
    {
        [, , $total] = $this->bounds();
        $live = $this->live(['ai.limits.surfaces.vendor.daily_tokens' => $total - 1]);

        try {
            $this->engine()->run($this->req());
            $this->fail('expected refusal');
        } catch (AiBudgetExceededException) {
        }

        $this->assertSame(0, $live->calls);
        $this->assertSame(0, $this->usage()['global']['tokens'], 'global was charged first and must have been rolled back');
        $this->assertSame(0, $this->usage()['global']['requests']);
        $this->assertSame(0, $this->usage()['global']['cost_micro_usd']);
    }

    // ------------------------------------------------------------------------- missing / unsafe configuration

    public function test_every_missing_limit_on_either_scope_blocks_a_live_provider(): void
    {
        foreach (['ai.limits.global', 'ai.limits.surfaces.vendor'] as $path) {
            foreach (['daily_requests', 'daily_tokens', 'daily_cost_micro_usd'] as $key) {
                $this->resetUsage();
                $live = $this->live(["{$path}.{$key}" => null]);

                try {
                    $this->engine()->run($this->req());
                    $this->fail("{$path}.{$key} = null must block");
                } catch (AiConfigurationException) {
                }

                $this->assertSame(0, $live->calls, "{$path}.{$key}");
                $this->assertSame(0, $this->usage()['global']['requests'], "nothing is counted when {$path}.{$key} is missing");
            }
        }
    }

    public function test_a_zero_limit_is_a_hard_block_and_invalid_limits_are_configuration_errors(): void
    {
        $live = $this->live(['ai.limits.surfaces.vendor.daily_requests' => 0]);

        try {
            $this->engine()->run($this->req());
            $this->fail('zero must block');
        } catch (AiBudgetExceededException) {
        }
        $this->assertSame(0, $live->calls);

        foreach (['unlimited', -1, 1.5, true, 'PHP_INT_MAX'] as $bad) {
            $this->resetUsage();
            $this->live(['ai.limits.global.daily_cost_micro_usd' => $bad]);

            try {
                $this->engine()->run($this->req());
                $this->fail('an invalid limit must never authorise a live request');
            } catch (AiConfigurationException) {
            }
        }
        $this->assertSame(0, $live->calls);
    }

    public function test_a_live_provider_without_complete_pricing_cannot_reserve_a_maximum_cost(): void
    {
        foreach ([[], ['input_usd_per_million' => 2.0], ['input_usd_per_million' => 0, 'output_usd_per_million' => 6.0], ['input_usd_per_million' => 'x', 'output_usd_per_million' => 6.0]] as $entry) {
            $this->resetUsage();
            $live = $this->live(['ai.pricing.fakelive.test-model' => $entry]);

            try {
                $this->engine()->run($this->req());
                $this->fail('pricing is never guessed');
            } catch (AiConfigurationException) {
            }

            $this->assertSame(0, $live->calls);
        }
    }

    public function test_the_offline_mock_still_runs_without_any_limits(): void
    {
        $this->engine()->run($this->req());
        $this->assertSame(1, $this->mockProvider()->callCount());
    }

    // ------------------------------------------------------------------------------------------- settlement

    public function test_unknown_usage_after_the_request_was_sent_keeps_the_full_reservation(): void
    {
        [, , $total, $cost] = $this->bounds();
        $live = $this->live();
        $live->queue(new AiResponse('ok', 'fakelive', 'test-model', new AiUsage(null, null)));

        $this->engine()->run($this->req());

        $this->assertSame($total, $this->usage()['global']['tokens']);
        $this->assertSame($cost, $this->usage()['global']['cost_micro_usd']);
        $this->assertSame($total, $this->usage()[self::SURFACE_SCOPE]['tokens']);
        $this->assertSame($cost, $this->usage()[self::SURFACE_SCOPE]['cost_micro_usd']);
    }

    public function test_known_usage_replaces_the_reservation_with_the_reported_tokens_and_cost(): void
    {
        $live = $this->live();
        $live->queue(new AiResponse('ok', 'fakelive', 'test-model', new AiUsage(40, 10)));

        $this->engine()->run($this->req());

        $this->assertSame(50, $this->usage()['global']['tokens']);
        // 40 x $2/M + 10 x $6/M = 140 micro-USD.
        $this->assertSame(140, $this->usage()['global']['cost_micro_usd']);
        $this->assertSame(140, $this->usage()[self::SURFACE_SCOPE]['cost_micro_usd']);
    }

    public function test_a_timeout_after_possible_transmission_keeps_the_reservation_and_is_not_retried(): void
    {
        [, , $total, $cost] = $this->bounds();
        $live = $this->live(['ai.request.max_retries' => 2]);
        $live->queue(AiProviderException::timeout(), new AiResponse('would be a second charge', 'fakelive', 'test-model', new AiUsage(1, 1)));

        try {
            $this->engine()->run($this->req());
            $this->fail('the timeout must surface');
        } catch (AiProviderException $e) {
            $this->assertSame(AiProviderException::TIMEOUT, $e->kind);
        }

        $this->assertSame(1, $live->calls, 'an ambiguous live attempt is never repeated: the provider has no idempotency key');
        $this->assertSame($total, $this->usage()['global']['tokens']);
        $this->assertSame($cost, $this->usage()['global']['cost_micro_usd']);
        $this->assertSame(1, $this->usage()['global']['requests']);
    }

    public function test_an_ambiguous_server_error_after_sending_also_keeps_the_reservation_and_is_not_retried(): void
    {
        [, , $total, $cost] = $this->bounds();
        $live = $this->live(['ai.request.max_retries' => 2]);
        $live->queue(AiProviderException::unavailable());

        try {
            $this->engine()->run($this->req());
        } catch (AiProviderException) {
        }

        $this->assertSame(1, $live->calls);
        $this->assertSame($total, $this->usage()['global']['tokens']);
        $this->assertSame($cost, $this->usage()['global']['cost_micro_usd']);
    }

    public function test_a_failure_before_transmission_releases_tokens_and_cost_but_still_counts_the_request(): void
    {
        $live = $this->live(['ai.request.max_retries' => 0]);
        $live->queue(AiProviderException::unavailable('never connected', false));

        try {
            $this->engine()->run($this->req());
        } catch (AiProviderException) {
        }

        $this->assertSame(0, $this->usage()['global']['tokens']);
        $this->assertSame(0, $this->usage()['global']['cost_micro_usd']);
        $this->assertSame(0, $this->usage()[self::SURFACE_SCOPE]['tokens']);
        $this->assertSame(1, $this->usage()['global']['requests'], 'a failing provider cannot be hammered past the request cap');
        $this->assertSame(1, $this->usage()['global']['failures']);
    }

    public function test_a_provider_refusal_such_as_rate_limiting_releases_the_reservation(): void
    {
        $live = $this->live();
        $live->queue(AiProviderException::rateLimited(30));

        try {
            $this->engine()->run($this->req());
        } catch (AiProviderException) {
        }

        $this->assertSame(0, $this->usage()['global']['tokens']);
        $this->assertSame(0, $this->usage()['global']['cost_micro_usd']);
        $this->assertSame(1, $this->usage()['global']['requests']);
    }

    // --------------------------------------------------------------------------------------------- retries

    public function test_a_retry_after_a_provably_unsent_attempt_is_allowed_counts_as_a_request_and_reuses_the_reservation(): void
    {
        [, , $total] = $this->bounds();
        $live = $this->live(['ai.request.max_retries' => 1]);
        $live->queue(AiProviderException::unavailable('refused', false), new AiResponse('ok', 'fakelive', 'test-model', new AiUsage(100, 50)));

        $result = $this->engine()->run($this->req());

        $this->assertSame(2, $live->calls);
        $this->assertSame(2, $result->attempts);
        $this->assertSame(2, $this->usage()['global']['requests'], 'the retry is a second provider request');
        $this->assertSame(150, $this->usage()['global']['tokens'], 'no tokens were spent by the unsent attempt, so only the final usage remains');
        $this->assertLessThan($total, 150);
    }

    public function test_a_retry_that_could_be_charged_again_needs_its_own_reservation_or_is_not_sent(): void
    {
        // The offline mock is the only provider whose ambiguous failures may be repeated (it costs nothing). The budget
        // semantics still apply: the first attempt's bound stays held and the retry reserves another.
        config(['ai.request.max_retries' => 1, 'ai.pricing.mock.mock-model' => ['input_usd_per_million' => 2.0, 'output_usd_per_million' => 6.0]]);
        $r = $this->req()->resolved('mock-model', 512, 15);
        $e = app(TokenEstimator::class);
        $bound = $e->estimate($r);
        $cost = (int) app(PriceTable::class)->maxCostMicroUsd('mock', 'mock-model', $e->inputUpperBound($r), $e->outputUpperBound($r));

        $this->mockProvider()->timeoutOnce();
        $this->mockProvider()->respondWith('ok', new AiUsage(10, 5));
        $this->engine()->run($this->req());

        $this->assertSame(2, $this->mockProvider()->callCount());
        $this->assertSame($bound + 15, $this->usage()['global']['tokens'], 'first attempt stays charged in full; the retry settled to its reported usage');
        $this->assertGreaterThan($cost, $this->usage()['global']['cost_micro_usd']);

        // With room for only ONE bound the retry cannot be admitted: the original error surfaces and nothing is resent.
        $this->resetUsage();
        $this->mockProvider()->reset();
        config(['ai.limits.global.daily_tokens' => $bound + 10]);
        $this->mockProvider()->timeoutOnce();
        $this->mockProvider()->respondWith('never sent');

        try {
            $this->engine()->run($this->req());
            $this->fail('expected the timeout');
        } catch (AiProviderException $ex) {
            $this->assertSame(AiProviderException::TIMEOUT, $ex->kind);
        }

        $this->assertSame(1, $this->mockProvider()->callCount(), 'a retry that cannot be reserved is never sent');
        $this->assertLessThanOrEqual($bound + 10, $this->usage()['global']['tokens'], 'and it never pushed the counter past its limit');
    }

    public function test_settlement_and_failure_are_idempotent(): void
    {
        $guard = app(BudgetGuard::class);

        $r = $guard->reserve(AiSurface::Vendor, 1000, false, 400);
        $guard->settle($r, 300, 100);
        $guard->settle($r, 300, 100);
        $guard->fail($r, false);
        $guard->fail($r, false);

        $this->assertSame(300, $this->usage()['global']['tokens']);
        $this->assertSame(100, $this->usage()['global']['cost_micro_usd']);
        $this->assertSame(1, $this->usage()['global']['successes']);
        $this->assertSame(0, $this->usage()['global']['failures']);

        $f = $guard->reserve(AiSurface::Vendor, 1000, false, 400);
        $guard->fail($f, false);
        $guard->fail($f, false);
        $guard->settle($f, 5, 1);

        $this->assertSame(300, $this->usage()['global']['tokens'], 'the second reservation was released exactly once');
        $this->assertSame(100, $this->usage()['global']['cost_micro_usd']);
    }

    // ------------------------------------------------------------------- cache failure and counter loss

    /** @return array<string,array{0:\Closure}> ways the durable accounting can be damaged behind the engine's back */
    public static function damage(): array
    {
        return [
            'counter row deleted' => [fn () => DB::table('ai_usage_counters')->where('scope', 'global')->delete()],
            'tokens counter zeroed' => [fn () => DB::table('ai_usage_counters')->where('scope', 'global')->update(['tokens' => 0])],
            'requests counter lowered' => [fn () => DB::table('ai_usage_counters')->where('scope', 'global')->update(['requests' => 0])],
            'surface counter lowered' => [fn () => DB::table('ai_usage_counters')->where('scope', 'surface.vendor')->update(['cost_micro_usd' => 0])],
            'counter driven negative' => [fn () => DB::table('ai_usage_counters')->where('scope', 'global')->update(['tokens' => -5000])],
            'counter inflated' => [fn () => DB::table('ai_usage_counters')->where('scope', 'global')->update(['tokens' => 99_999_999])],
            'ledger rows deleted' => [fn () => DB::table('ai_budget_reservations')->delete()],
        ];
    }

    #[DataProvider('damage')]
    public function test_lost_or_damaged_accounting_never_reads_as_zero_usage_and_blocks_execution(\Closure $damage): void
    {
        $live = $this->live();
        $this->engine()->run($this->req());
        $this->assertSame(150, $this->usage()['global']['tokens']);

        $damage();

        try {
            $this->engine()->run($this->req());
            $this->fail('damaged accounting must stop execution');
        } catch (AiConfigurationException $e) {
            $this->assertStringContainsString('ledger', $e->getMessage().' ledger');
        }

        $this->assertSame(1, $live->calls, 'the second request never reached the provider');
        $this->assertLessThanOrEqual(1, DB::table('ai_budget_reservations')->count(), 'no new reservation was recorded');
    }

    public function test_an_unavailable_database_fails_closed_for_a_live_provider(): void
    {
        $live = $this->live();
        Schema::drop('ai_usage_counters'); // the accounting store is gone

        $this->expectException(AiConfigurationException::class);
        try {
            $this->engine()->run($this->req());
        } finally {
            $this->assertSame(0, $live->calls);
        }
    }

    public function test_a_database_that_fails_mid_reservation_leaves_nothing_behind(): void
    {
        $this->live();
        // The global counter is charged, then the SURFACE counter update fails: the global charge must not linger.
        $disarm = $this->failDatabaseWhen('requests = requests + 1', nth: 2);

        try {
            $this->engine()->run($this->req());
            $this->fail('expected a refusal');
        } catch (AiConfigurationException) {
        }

        $disarm();
        $this->assertSame(0, $this->usage()['global']['requests']);
        $this->assertSame(0, $this->usage()['global']['tokens']);
        $this->assertSame(0, DB::table('ai_budget_reservations')->count());
        $this->assertSame([], app(BudgetGuard::class)->integrityProblems(), 'the accounting is still consistent');
    }

    // ------------------------------------------------------------------------------------- daily boundary

    public function test_budgets_roll_over_at_midnight_without_touching_yesterdays_counters(): void
    {
        [, , $total] = $this->bounds();
        $this->live(['ai.limits.surfaces.vendor.daily_tokens' => $total]);

        Carbon::setTestNow('2026-03-10 23:59:30');
        $this->engine()->run($this->req());
        $yesterday = app(UsageMeter::class)->snapshot('20260310');
        $this->assertSame(1, $yesterday['global']['requests']);
        $this->assertSame(150, $yesterday['surface.vendor']['tokens']);

        Carbon::setTestNow('2026-03-11 00:00:30');
        $this->engine()->run($this->req());

        $today = app(UsageMeter::class)->snapshot('20260311');
        $this->assertSame(1, $today['global']['requests'], 'the new day starts from zero');
        $this->assertSame(150, $today['surface.vendor']['tokens']);
        $this->assertSame($yesterday, app(UsageMeter::class)->snapshot('20260310'), "yesterday's counters are untouched");
    }

    // ------------------------------------------------------------------------------ no hard-budget overshoot

    public function test_a_bound_violation_is_recorded_flags_the_day_and_stops_live_execution(): void
    {
        $live = $this->live();
        // The provider reports far more than was reserved (the bound's assumption failed).
        $live->queue(new AiResponse('ok', 'fakelive', 'test-model', new AiUsage(90_000, 500)));

        $this->engine()->run($this->req());

        $this->assertSame(90_500, $this->usage()['global']['tokens'], 'the truth is recorded in full');
        $this->assertTrue(app(UsageMeter::class)->integrityFlagged(app(UsageMeter::class)->today()));

        $critical = array_values(array_filter($this->logRecords(), fn ($r) => $r['message'] === 'ai.budget.bound_violated'));
        $this->assertCount(1, $critical);
        $this->assertSame(90_500, $critical[0]['context']['actual_tokens']);
        $this->assertStringNotContainsString('Hello', $this->logDump());

        try {
            $this->engine()->run($this->req());
            $this->fail('the flagged day must refuse live execution');
        } catch (AiConfigurationException) {
        }
        $this->assertSame(1, $live->calls);
    }

    public function test_sequential_requests_can_never_push_a_counter_past_its_limit(): void
    {
        [, , $total, $cost] = $this->bounds();
        $limit = $total + 300;
        $live = $this->live([
            'ai.limits.global.daily_tokens' => $limit,
            'ai.limits.surfaces.vendor.daily_tokens' => $limit,
            'ai.limits.global.daily_cost_micro_usd' => $cost + 5000,
            'ai.limits.surfaces.vendor.daily_cost_micro_usd' => $cost + 5000,
        ]);

        // Worst case for the bound: the provider uses EXACTLY what was reserved.
        $in = $total - 512;
        for ($i = 0; $i < 12; $i++) {
            $live->queue(new AiResponse('ok', 'fakelive', 'test-model', new AiUsage($in, 512)));
        }

        $admitted = 0;
        for ($i = 0; $i < 12; $i++) {
            try {
                $this->engine()->run($this->req());
                $admitted++;
            } catch (AiBudgetExceededException) {
            }

            $u = $this->usage();
            $this->assertLessThanOrEqual($limit, $u['global']['tokens'], "iteration {$i}");
            $this->assertLessThanOrEqual($cost + 5000, $u['global']['cost_micro_usd'], "iteration {$i}");
        }

        $this->assertSame(1, $admitted, 'with room for one worst-case request plus 300 tokens, exactly one is admitted');
        $this->assertSame(1, $live->calls);
    }

    // ----------------------------------------------------------------------------------------------- helpers

    private function resetUsage(): void
    {
        $this->freshEngineState();
    }
}
