<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Data\AiResponse;
use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Data\AiUsage;
use App\Services\Ai\Exceptions\AiBudgetExceededException;
use App\Services\Ai\Exceptions\AiConfigurationException;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Usage\BudgetGuard;
use App\Services\Ai\Usage\BudgetReservation;
use App\Services\Ai\Usage\PriceTable;
use App\Services\Ai\Usage\TokenEstimator;
use App\Services\Ai\Usage\UsageMeter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Ai\Support\FakeLiveProvider;

/**
 * M5.7.1: the AI budget lives in the database, not in the cache. Clearing the cache (an eviction, `cache:clear`, a restart)
 * must never give spent budget back, and every way the durable accounting can fail or be damaged must refuse the request.
 *
 * The same tests run on SQLite (in-memory) and on MariaDB (`DB_CONNECTION=mysql ...`, see docs/ai/06). The reservations here
 * are real ledger transactions; only the provider is a fake that never touches the network.
 */
class DurableBudgetTest extends AiTestCase
{
    /** Tokens and cost the engine reserves for one standard request, and what the fake provider reports back (150 / 500). */
    private function bounds(): array
    {
        $this->liveBudgets();
        $r = $this->req('Hello')->resolved('test-model', 512, 15);
        $e = app(TokenEstimator::class);
        $in = $e->inputUpperBound($r);
        $out = $e->outputUpperBound($r);

        return [$in + $out, (int) app(PriceTable::class)->maxCostMicroUsd('fakelive', 'test-model', $in, $out)];
    }

    private function live(array $overrides = []): FakeLiveProvider
    {
        $live = $this->useLiveProvider();
        $this->liveBudgets($overrides);

        return $live;
    }

    private function clearEveryCache(): void
    {
        Cache::flush();
        Artisan::call('cache:clear');
        $this->releaseAllLocks();
    }

    private function releaseAllLocks(): void
    {
        foreach (range(0, 5) as $i) {
            Cache::lock("ai:slot:{$i}")->forceRelease();
        }
    }

    // ------------------------------------------------------------------------------------ cache clearing

    /** @return array<string,array{0:string,1:string}> the six hard limits: three metrics on each of the two scopes */
    public static function limits(): array
    {
        $cases = [];

        foreach (['global' => 'ai.limits.global', 'surface' => 'ai.limits.surfaces.vendor'] as $scope => $path) {
            foreach (['requests', 'tokens', 'cost_micro_usd'] as $metric) {
                $cases["{$scope} {$metric}"] = [$path.'.daily_'.$metric, $metric];
            }
        }

        return $cases;
    }

    #[DataProvider('limits')]
    public function test_clearing_the_cache_never_gives_back_spent_budget(string $limitKey, string $metric): void
    {
        [$total, $cost] = $this->bounds();
        // Room for exactly ONE request: it settles to 150 tokens / 500 micro-USD, so a second needs a fresh worst case on top.
        $limit = match ($metric) {
            'requests' => 1,
            'tokens' => $total + 100,
            'cost_micro_usd' => $cost + 100,
        };
        $live = $this->live([$limitKey => $limit]);

        $this->engine()->run($this->req());
        $before = $this->usage();
        $this->assertSame(1, $before['global']['requests']);
        $this->assertSame(150, $before['global']['tokens']);

        $this->clearEveryCache();

        $this->assertSame($before, $this->usage(), 'the original consumption is still there after the cache was cleared');

        try {
            $this->engine()->run($this->req());
            $this->fail("the {$limitKey} limit must still hold after a cache clear");
        } catch (AiBudgetExceededException $e) {
            $this->assertStringContainsString($metric, $e->getMessage());
        }

        $this->assertSame(1, $live->calls, 'the provider was not reached by the refused request');
        $this->assertSame($before, $this->usage(), 'a refusal leaves no trace');
        $this->assertSame([], app(BudgetGuard::class)->integrityProblems());
    }

    public function test_a_cache_clear_while_a_reservation_is_held_neither_frees_it_nor_loses_it(): void
    {
        [$total, $cost] = $this->bounds();
        $this->live(['ai.limits.global.daily_tokens' => $total, 'ai.limits.global.daily_cost_micro_usd' => $cost]);
        $guard = app(BudgetGuard::class);

        $held = $guard->reserve(AiSurface::Vendor, $total, true, $cost);
        $this->clearEveryCache();

        try {
            $guard->reserve(AiSurface::Customer, $total, true, $cost);
            $this->fail('the held reservation still occupies the whole budget');
        } catch (AiBudgetExceededException) {
        }

        $guard->fail($held, false);   // provably not processed: released
        $this->clearEveryCache();
        $guard->reserve(AiSurface::Customer, $total, true, $cost); // and now it fits

        $this->assertSame(2, $this->usage()['global']['requests'], 'both attempts were counted');
    }

    public function test_a_cache_clear_in_the_middle_of_a_provider_call_does_not_disturb_settlement(): void
    {
        $live = $this->live();
        $live->queue(function () {
            $this->clearEveryCache(); // an operator clears the cache while the provider is thinking

            return new AiResponse('ok', 'fakelive', 'test-model', new AiUsage(10, 5));
        });

        $this->engine()->run($this->req());

        $this->assertSame(15, $this->usage()['global']['tokens'], 'settled to the reported usage');
        $this->assertSame(1, $this->usage()['global']['successes']);
        $this->assertSame(BudgetGuard::SETTLED, DB::table('ai_budget_reservations')->value('state'));
    }

    public function test_the_budget_integrity_flag_survives_a_cache_clear(): void
    {
        $live = $this->live();
        $live->queue(new AiResponse('ok', 'fakelive', 'test-model', new AiUsage(90_000, 500))); // far more than was reserved

        $this->engine()->run($this->req());
        $this->assertTrue(app(UsageMeter::class)->integrityFlagged(app(UsageMeter::class)->today()));

        $this->clearEveryCache();

        $this->assertTrue(app(UsageMeter::class)->integrityFlagged(app(UsageMeter::class)->today()), 'a cleared cache does not clear the flag');
        $this->expectException(AiConfigurationException::class);
        $this->engine()->run($this->req());
    }

    // ------------------------------------------------------------------------------------ idempotency

    public function test_reserving_with_the_same_id_twice_charges_once(): void
    {
        $guard = app(BudgetGuard::class);
        $id = '01hzzzzzzzzzzzzzzzzzzzzzzz';

        $a = $guard->reserve(AiSurface::Vendor, 100, false, 40, reservationId: $id);
        $b = $guard->reserve(AiSurface::Vendor, 100, false, 40, reservationId: $id);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, DB::table('ai_budget_reservations')->count());
        $this->assertSame(1, $this->usage()['global']['requests']);
        $this->assertSame(100, $this->usage()['global']['tokens']);
        $this->assertSame(40, $this->usage()['surface.vendor']['cost_micro_usd']);
    }

    public function test_a_reservation_id_cannot_be_reused_for_another_surface(): void
    {
        $guard = app(BudgetGuard::class);
        $a = $guard->reserve(AiSurface::Vendor, 100, false);

        $this->expectException(AiConfigurationException::class);
        $guard->reserve(AiSurface::Customer, 100, false, reservationId: $a->id);
    }

    public function test_settlement_is_idempotent_even_through_independent_copies_of_the_reservation(): void
    {
        $guard = app(BudgetGuard::class);
        $r = $guard->reserve(AiSurface::Vendor, 1000, false, 400);
        $copy = new BudgetReservation($r->id, $r->day, $r->surface, $r->estimatedTokens, $r->estimatedCostMicroUsd);

        $guard->settle($r, 300, 100);
        $guard->settle($copy, 300, 100);   // a duplicate delivered by another code path / process
        $guard->settle($copy, 999, 999);   // even with different numbers
        $guard->fail($copy, false);        // and a contradictory failure
        $guard->fail($r, true);

        $u = $this->usage();
        $this->assertSame(300, $u['global']['tokens']);
        $this->assertSame(100, $u['global']['cost_micro_usd']);
        $this->assertSame(1, $u['global']['successes']);
        $this->assertSame(0, $u['global']['failures']);
        $this->assertSame(BudgetGuard::SETTLED, DB::table('ai_budget_reservations')->value('state'));
        $this->assertFalse(app(UsageMeter::class)->integrityFlagged($r->day), 'the ignored duplicate (999) was never applied');
    }

    // ------------------------------------------------------------------------------------ unknown outcomes

    public function test_outcomes_the_engine_cannot_be_sure_of_keep_the_full_reservation_and_only_certain_ones_release_it(): void
    {
        $guard = app(BudgetGuard::class);
        $cases = [
            'success, usage unknown' => [fn ($r) => $guard->settle($r, null, null), BudgetGuard::SETTLED_UNKNOWN, 500, 80],
            'failure that may have been processed' => [fn ($r) => $guard->fail($r, true), BudgetGuard::FAILED_UNKNOWN, 500, 80],
            'failure that provably was not processed' => [fn ($r) => $guard->fail($r, false), BudgetGuard::RELEASED, 0, 0],
            'never closed (the process died)' => [fn ($r) => null, BudgetGuard::OPEN, 500, 80],
            'success, usage known' => [fn ($r) => $guard->settle($r, 120, 30), BudgetGuard::SETTLED, 120, 30],
        ];

        foreach ($cases as $label => [$close, $state, $tokens, $cost]) {
            $this->prepareDurableAiState();
            $r = $guard->reserve(AiSurface::Vendor, 500, false, 80);
            $close($r);

            $row = DB::table('ai_budget_reservations')->where('id', $r->id)->first();
            $this->assertSame($state, $row->state, $label);
            $this->assertSame($tokens, (int) $row->tokens, "{$label}: tokens still charged");
            $this->assertSame($cost, (int) $row->cost_micro_usd, "{$label}: cost still charged");
            $this->assertSame($tokens, $this->usage()['global']['tokens'], "{$label}: counters follow the ledger");
            $this->assertSame(1, $this->usage()['global']['requests'], "{$label}: always counted as a request");
            $this->assertSame([], $guard->integrityProblems(), $label);
        }
    }

    public function test_a_reservation_that_is_never_closed_stays_charged_and_is_reported_as_stale(): void
    {
        $guard = app(BudgetGuard::class);
        $guard->reserve(AiSurface::Vendor, 500, false, 80);

        $this->assertSame(0, $guard->staleOpenReservations(900));

        Carbon::setTestNow(now()->addMinutes(30));

        $this->assertSame(1, $guard->staleOpenReservations(900));
        $this->assertSame(500, $this->usage()['global']['tokens'], 'time does not release it');
    }

    public function test_a_reservation_settles_against_the_day_it_was_made_on_even_across_midnight(): void
    {
        $guard = app(BudgetGuard::class);

        Carbon::setTestNow('2026-03-10 23:59:55');
        $r = $guard->reserve(AiSurface::Vendor, 500, false);

        Carbon::setTestNow('2026-03-11 00:00:05');
        $guard->settle($r, 200, null);

        $this->assertSame(200, app(UsageMeter::class)->snapshot('20260310')['global']['tokens'], "yesterday's counter got the settlement");
        $this->assertSame(0, app(UsageMeter::class)->snapshot('20260311')['global']['tokens'], "today's counter was not touched");
        $this->assertSame([], $guard->integrityProblems('20260310'));
    }

    // ------------------------------------------------------------------------------------ retries

    public function test_every_retry_has_its_own_reservation_linked_to_the_attempt_it_retries(): void
    {
        config(['ai.request.max_retries' => 1]);
        // A live provider is never retried after an ambiguous failure, so the first attempt provably was not sent.
        $live = $this->live();
        $live->queue(AiProviderException::unavailable('refused', requestSent: false), new AiResponse('ok', 'fakelive', 'test-model', new AiUsage(10, 5)));

        $this->engine()->run($this->req());

        $rows = DB::table('ai_budget_reservations')->orderBy('created_at')->orderBy('id')->get();
        $this->assertCount(2, $rows, 'two provider attempts, two reservations');
        $first = $rows->firstWhere('retry_of', null);
        $second = $rows->firstWhere('retry_of', $first->id);

        $this->assertNotNull($second);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(BudgetGuard::RELEASED, $first->state, 'the unsent attempt was released');
        $this->assertSame(BudgetGuard::SETTLED, $second->state);
        $this->assertSame($first->id, $live->requests[0]->reservationId, 'each attempt names the reservation that pays for it');
        $this->assertSame($second->id, $live->requests[1]->reservationId);
        $this->assertSame(2, $this->usage()['global']['requests'], 'both attempts count as requests');
        $this->assertSame(15, $this->usage()['global']['tokens']);
    }

    public function test_a_retry_that_cannot_be_reserved_changes_nothing_and_the_first_attempt_is_closed_as_unknown(): void
    {
        config(['ai.request.max_retries' => 1]);
        $this->mockProvider()->timeoutOnce()->respondWith('never sent');
        $tokens = app(TokenEstimator::class)->estimate($this->req()->resolved('mock-model', 512, 20));
        config(['ai.limits.global.daily_tokens' => $tokens + 10]); // room for ONE attempt only

        try {
            $this->engine()->run($this->req());
            $this->fail('the timeout surfaces');
        } catch (AiProviderException $e) {
            $this->assertSame(AiProviderException::TIMEOUT, $e->kind);
        }

        $this->assertSame(1, $this->mockProvider()->callCount(), 'the retry was never sent');
        $row = DB::table('ai_budget_reservations')->first();
        $this->assertSame(1, DB::table('ai_budget_reservations')->count(), 'no second reservation was created');
        $this->assertSame(BudgetGuard::FAILED_UNKNOWN, $row->state, 'a timeout may have been processed: the reservation stands');
        $this->assertSame($tokens, (int) $row->tokens);
        $this->assertSame($tokens, $this->usage()['global']['tokens']);
        $this->assertSame(1, $this->usage()['global']['failures']);
    }

    // ------------------------------------------------------------------------------------ database failures

    public function test_the_mock_provider_is_refused_too_when_the_ledger_cannot_be_written(): void
    {
        Schema::drop('ai_budget_reservations');

        try {
            $this->engine()->run($this->req());
            $this->fail('no accounting, no request');
        } catch (AiConfigurationException) {
        }

        $this->assertSame(0, $this->mockProvider()->callCount(), 'database failures fail closed for every provider');
    }

    public function test_a_failure_to_read_the_ledger_before_sending_stops_the_provider_call(): void
    {
        $live = $this->live();
        // The reservation succeeds (its existence check is the 1st read of that shape), then the provider-side check - the 2nd -
        // cannot read the ledger.
        $disarm = $this->failDatabaseWhen('select * from ai_budget_reservations where id', nth: 2);

        try {
            $this->engine()->run($this->req());
            $this->fail('the request must not be answered');
        } catch (AiProviderException $e) {
            $this->assertFalse($e->mayHaveConsumedTokens(), 'nothing was sent');
        }

        $disarm();
        $this->assertSame(0, $live->calls, 'the provider was not reached');
        $this->assertSame(BudgetGuard::RELEASED, DB::table('ai_budget_reservations')->value('state'), 'and the unused reservation was released');
        $this->assertSame(0, $this->usage()['global']['tokens']);
    }

    public function test_settlement_failing_after_a_good_answer_leaves_the_reservation_open_and_fully_charged(): void
    {
        $live = $this->live();
        $disarm = $this->failDatabaseWhen('update ai_budget_reservations set state');

        try {
            $this->engine()->run($this->req());
            $this->fail('the engine fails closed');
        } catch (AiConfigurationException) {
        }

        $disarm();
        $this->assertSame(1, $live->calls);
        $this->assertSame(BudgetGuard::OPEN, DB::table('ai_budget_reservations')->value('state'));
        $this->assertGreaterThan(150, $this->usage()['global']['tokens'], 'the whole worst case stays charged');
        $this->assertSame([], app(BudgetGuard::class)->integrityProblems());
    }

    // ------------------------------------------------------------------------------------ structure

    public function test_the_cache_is_not_used_for_the_budget_at_all(): void
    {
        foreach (['Usage/BudgetGuard.php', 'Usage/UsageMeter.php', 'Usage/BudgetReservation.php', 'Safety/KillSwitch.php'] as $file) {
            $source = (string) file_get_contents(app_path('Services/Ai/'.$file));

            $this->assertStringNotContainsString('Cache::', $source, "{$file} must not touch the cache");
            $this->assertStringNotContainsString('cache(', $source, $file);
            $this->assertStringNotContainsString('Illuminate\\Support\\Facades\\Cache', $source, $file);
        }
    }

    public function test_the_ledger_holds_numbers_ids_and_states_only(): void
    {
        $allowed = [
            'ai_usage_counters' => ['id', 'day', 'scope', 'requests', 'tokens', 'cost_micro_usd', 'successes', 'failures', 'created_at', 'updated_at'],
            'ai_budget_reservations' => ['id', 'day', 'surface', 'state', 'retry_of', 'requests', 'tokens', 'cost_micro_usd', 'reserved_tokens', 'reserved_cost_micro_usd', 'created_at', 'closed_at', 'anchor_seq'],
            'ai_runtime_flags' => ['name', 'value', 'reason', 'created_at', 'updated_at'],
        ];

        foreach ($allowed as $table => $columns) {
            $actual = Schema::getColumnListing($table);
            sort($actual);
            sort($columns);
            $this->assertSame($columns, $actual, "{$table}: no column that could hold a prompt, an answer or an identity");
        }
    }
}
