<?php

namespace Tests\Feature\Ai\CustomerAssistant;

use App\Services\Ai\CustomerAssistant\CustomerAssistant;
use App\Services\Ai\Data\AiMessage;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiResponse;
use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Data\AiUsage;
use App\Services\Ai\Exceptions\AiBusyException;
use App\Services\Ai\Exceptions\AiConfigurationException;
use App\Services\Ai\Safety\ConcurrencyGuard;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Ai\Support\FakeLiveProvider;

/**
 * Bounded synchronous execution: a turn has ONE deadline and ONE slot, nested timeouts are enforced for a live provider,
 * and a turn that has used its time is refused instead of starting another provider round.
 */
class TurnDeadlineAndSlotTest extends CustomerAssistantTestCase
{
    private function guard(): ConcurrencyGuard
    {
        return new ConcurrencyGuard;
    }

    private function request(?float $deadlineAt): AiRequest
    {
        return new AiRequest(AiSurface::Customer, 'guest:t', [AiMessage::user('hi')], deadlineAt: $deadlineAt);
    }

    // ---------------------------------------------------------------------------------------- deadlines

    public function test_a_round_with_less_than_two_seconds_left_is_refused_before_anything_is_spent(): void
    {
        foreach ([microtime(true) + 1.0, microtime(true) - 5, microtime(true)] as $deadline) {
            try {
                $this->engine()->run($this->request($deadline));
                $this->fail('the round must not start');
            } catch (AiBusyException) {
            }
        }

        $this->assertSame(0, $this->mockProvider()->callCount());
        $this->assertSame(0, $this->usage()['global']['requests'], 'nothing was reserved');
    }

    public function test_a_round_with_enough_time_runs_and_its_attempt_is_clamped_to_the_time_left(): void
    {
        config(['ai.request.timeout_seconds' => 20, 'ai.request.max_timeout_seconds' => 30]);

        $this->engine()->run($this->request(microtime(true) + 6.5));

        $this->assertLessThanOrEqual(6, $this->mockProvider()->calls()[0]->timeoutSeconds, 'an attempt can never outlive the turn');

        $this->mockProvider()->reset();
        $this->engine()->run($this->request(null));
        $this->assertSame(20, $this->mockProvider()->calls()[0]->timeoutSeconds, 'no turn deadline: the configured attempt timeout applies');
    }

    public function test_both_rounds_of_a_customer_turn_share_one_deadline_derived_from_configuration(): void
    {
        $this->catalogue();
        config(['ai.request.turn_deadline_seconds' => 33]);
        $this->mockProvider()->queue(
            $this->toolCallResponse([$this->toolCall('customer_search_products', ['query' => 'mtn'])]),
            $this->textResponse('done'),
        );

        $before = microtime(true);
        $this->say('mtn?')->assertOk();
        $after = microtime(true);

        [$first, $second] = $this->mockProvider()->calls();

        $this->assertSame($first->deadlineAt, $second->deadlineAt, 'one deadline for the whole turn, not one per round');
        $this->assertGreaterThanOrEqual($before + 33, $first->deadlineAt);
        $this->assertLessThanOrEqual($after + 33, $first->deadlineAt);
    }

    public function test_a_turn_that_has_used_its_time_in_round_one_is_not_given_a_second_round(): void
    {
        $this->catalogue();
        config(['ai.request.turn_deadline_seconds' => 3]);
        $this->mockProvider()->queue(
            function () {
                usleep(1_300_000); // round 1 + tools "take" 1.3s of the 3s turn: under 2s remain

                return $this->toolCallResponse([$this->toolCall('customer_search_products', ['query' => 'mtn'])]);
            },
            $this->textResponse('must never be requested'),
        );

        $this->say('mtn?')->assertStatus(503);

        $this->assertSame(1, $this->mockProvider()->callCount(), 'the second provider round was never started');
        $this->assertFalse(Cache::lock('ai:slot:0')->get() === false, 'the slot was released');
        Cache::lock('ai:slot:0')->forceRelease();
    }

    // ----------------------------------------------------------------------------- nested live timeouts

    public function test_a_live_provider_refuses_timeouts_that_do_not_nest(): void
    {
        $live = $this->useLiveProvider();
        $this->liveBudgets();

        $bad = [
            'attempt above max attempt' => ['ai.request.timeout_seconds' => 25, 'ai.request.max_timeout_seconds' => 20],
            'max attempt above round' => ['ai.request.max_timeout_seconds' => 30, 'ai.request.deadline_seconds' => 20],
            'round above turn' => ['ai.request.deadline_seconds' => 40, 'ai.request.turn_deadline_seconds' => 30],
            'turn unset' => ['ai.request.turn_deadline_seconds' => null],
        ];

        foreach ($bad as $label => $change) {
            config(['ai.request.timeout_seconds' => 15, 'ai.request.max_timeout_seconds' => 20, 'ai.request.deadline_seconds' => 20, 'ai.request.turn_deadline_seconds' => 40]);
            config($change);

            try {
                $this->engine()->run($this->request(null));
                $this->fail("{$label}: must be refused");
            } catch (AiConfigurationException) {
            }
        }

        $this->assertSame(0, $live->calls, 'a mis-nested configuration never reaches the provider');
    }

    public function test_the_mock_provider_is_not_subject_to_the_live_timeout_rules(): void
    {
        config(['ai.request.turn_deadline_seconds' => 5, 'ai.request.deadline_seconds' => 40]);

        $this->assertSame('ok', $this->mockProvider()->respondWith('ok')->send($this->request(null)->resolved('m', 100, 5))->text);
    }

    // --------------------------------------------------------------------------------------------- slots

    public function test_a_turn_holds_one_slot_for_its_whole_length_and_releases_it_afterwards(): void
    {
        config(['ai.concurrency.max_in_flight' => 1]);
        $guard = $this->guard();
        $probe = null;

        $result = $guard->turn(function () use ($guard, &$probe) {
            $inner = $guard->acquire(); // the orchestrator's own acquire inside the turn
            $probe = (new ConcurrencyGuard);

            try {
                $probe->acquire();
                $other = 'acquired';
            } catch (AiBusyException) {
                $other = 'busy';
            }

            $inner->release(); // releasing the borrowed slot must not free the real one

            try {
                (new ConcurrencyGuard)->acquire();
                $afterInner = 'acquired';
            } catch (AiBusyException) {
                $afterInner = 'busy';
            }

            return [$other, $afterInner];
        });

        $this->assertSame(['busy', 'busy'], $result, 'a second worker is refused while the turn runs, even after the borrowed slot was "released"');

        $free = (new ConcurrencyGuard)->acquire();
        $this->assertNotNull($free, 'the slot is free after the turn');
        $free->release();
    }

    public function test_a_turn_that_throws_still_frees_its_slot(): void
    {
        config(['ai.concurrency.max_in_flight' => 1]);

        try {
            $this->guard()->turn(fn () => throw new \RuntimeException('boom'));
            $this->fail('the exception propagates');
        } catch (\RuntimeException) {
        }

        $slot = $this->guard()->acquire();
        $slot->release();
        $this->assertTrue(true);
    }

    public function test_nested_turns_do_not_take_a_second_slot(): void
    {
        config(['ai.concurrency.max_in_flight' => 1]);
        $guard = $this->guard();

        $this->assertSame('inner', $guard->turn(fn () => $guard->turn(fn () => 'inner')));
    }

    public function test_the_cap_is_global_across_guard_instances_and_never_queues(): void
    {
        config(['ai.concurrency.max_in_flight' => 2]);
        $a = $this->guard()->acquire();
        $b = $this->guard()->acquire();

        $started = microtime(true);
        try {
            $this->guard()->acquire();
            $this->fail('a third concurrent turn is refused');
        } catch (AiBusyException $e) {
            $this->assertNotNull($e->retryAfterSeconds ?? 5);
        }
        $this->assertLessThan(1.0, microtime(true) - $started, 'a refused turn never waits (it would hold a worker)');

        $a->release();
        $c = $this->guard()->acquire();
        $b->release();
        $c->release();
    }

    public function test_a_busy_public_endpoint_answers_without_calling_the_provider(): void
    {
        config(['ai.concurrency.max_in_flight' => 1]);
        $held = $this->guard()->acquire();

        $this->say('hello')->assertStatus(503);

        $this->assertSame(0, $this->mockProvider()->callCount());
        $held->release();
        $this->say('hello')->assertOk();
    }

    public function test_the_slot_outlives_the_longest_possible_turn(): void
    {
        config(['ai.concurrency.max_in_flight' => 1, 'ai.concurrency.lock_seconds' => 5, 'ai.request.deadline_seconds' => 20, 'ai.request.turn_deadline_seconds' => 40]);

        $source = (string) file_get_contents(app_path('Services/Ai/Safety/ConcurrencyGuard.php'));
        $this->assertStringContainsString("max(\$seconds, (int) config('ai.request.deadline_seconds', 40) + 5, (int) config('ai.request.turn_deadline_seconds', 0) + 5)", $source);
    }

    public function test_no_queue_or_streaming_machinery_exists_in_the_assistants(): void
    {
        foreach (['CustomerAssistant', 'VendorAssistant', 'Developer'] as $dir) {
            foreach (glob(app_path("Services/Ai/{$dir}/*.php")) as $file) {
                $src = (string) file_get_contents($file);

                $this->assertDoesNotMatchRegularExpression('/\bShouldQueue\b|::dispatch\(|dispatch\(|StreamedResponse|stream\(|text\/event-stream|->stream\b/', $src, basename($file));
            }
        }

        $this->assertInstanceOf(CustomerAssistant::class, app(CustomerAssistant::class));
    }

    public function test_a_live_turn_through_the_orchestrator_borrows_the_turn_slot(): void
    {
        $live = $this->useLiveProvider();
        $this->liveBudgets();
        config(['ai.concurrency.max_in_flight' => 1]);
        $live->queue(new AiResponse('hi', 'fakelive', 'test-model', new AiUsage(10, 5)));

        $this->assertInstanceOf(FakeLiveProvider::class, $live);
        $reply = app(ConcurrencyGuard::class)->turn(fn () => $this->engine()->run($this->request(microtime(true) + 30)));

        $this->assertSame('hi', $reply->response->text);
        $this->assertSame(1, $live->calls);
    }
}
