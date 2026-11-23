<?php

namespace Tests\Feature\Ai\CustomerAssistant;

use App\Services\Ai\Providers\OpenAiProvider;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Ai\Support\ConfiguresOpenAi;

/**
 * The public Customer Assistant driven through the REAL OpenAI adapter, against Http::fake() only. Proves what actually
 * leaves the server (data minimisation), that a whole two-round turn holds exactly one worker slot, and that every
 * budget/limit gate still sits in front of the provider.
 */
class OpenAiCustomerFlowTest extends CustomerAssistantTestCase
{
    use ConfiguresOpenAi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureOpenAi();
        config(['ai.concurrency.max_in_flight' => 2]);
    }

    private function toolTurn(?\Closure $onRequest = null): void
    {
        $round = 0;
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            OpenAiProvider::ENDPOINT => function () use (&$round, $onRequest) {
                $round++;
                if ($onRequest) {
                    $onRequest($round);
                }

                return Http::response($round === 1
                    ? $this->okBody('', ['output' => [1 => $this->functionCallItem('customer_search_products', ['query' => 'mtn'], 'call_1')]])
                    : $this->okBody('The cheapest MTN bundle is MTN 1GB at GHS 5.00.'));
            },
        ]);
    }

    public function test_a_full_customer_turn_works_through_the_adapter_in_two_requests(): void
    {
        $this->catalogue();
        $this->toolTurn();

        $this->say('cheapest MTN bundle?')->assertOk()
            ->assertJsonPath('answer', 'The cheapest MTN bundle is MTN 1GB at GHS 5.00.')
            ->assertJsonPath('tools_used', ['customer_search_products']);

        $this->assertCount(2, $this->sent());

        $second = $this->bodyOf($this->sent()[1]);
        $this->assertArrayNotHasKey('tools', $second, 'round 2 offers no tools');
        $this->assertSame(512, $second['max_output_tokens']);
        $this->assertSame(2, $this->usage()['surface.customer']['requests']);
        $this->assertSame(280, $this->usage()['surface.customer']['tokens'], 'settled to the reported usage of both rounds (2 x 140)');
    }

    public function test_personal_and_secret_data_in_a_message_never_leave_the_server(): void
    {
        $this->catalogue();
        $this->toolTurn();

        $secrets = [
            '0244123456' => 'a local phone number',
            '+233 24 412 3456' => 'an international phone number',
            'kwame.mensah@example.com' => 'an email address',
            'XTRA4U-ABCDEF123456' => 'a payment reference',
            'sk-abcdefghijklmnopqrstuvwxyz0123456789' => 'an API key',
        ];

        $message = 'My number is 0244123456 or +233 24 412 3456, email kwame.mensah@example.com, ref XTRA4U-ABCDEF123456, key sk-abcdefghijklmnopqrstuvwxyz0123456789. What MTN bundle is cheapest?';
        $this->say($message)->assertOk();

        $this->assertCount(2, $this->sent());

        foreach ($this->sent() as $request) {
            $wire = $request->body();
            foreach ($secrets as $needle => $what) {
                $this->assertStringNotContainsString($needle, $wire, "{$what} must not be transmitted");
                $this->assertStringNotContainsString(json_encode($needle), $wire);
            }
            $this->assertSame([], array_values(array_intersect(['user', 'safety_identifier', 'metadata', 'conversation', 'previous_response_id'], array_keys($this->bodyOf($request)))), 'no visitor or session identifier field is sent');
            $this->assertStringContainsString('What MTN bundle is cheapest?', $wire, 'the question itself still goes through');
        }

        $dump = $this->logDump();
        foreach (array_keys($secrets) as $needle) {
            $this->assertStringNotContainsString($needle, $dump);
        }
        foreach (['What MTN bundle is cheapest', self::FAKE_KEY, 'Bearer'] as $needle) {
            $this->assertStringNotContainsString($needle, $dump, 'no raw prompt, response or credential is logged');
        }
    }

    public function test_private_catalogue_fields_do_not_reach_the_provider_through_tool_results(): void
    {
        $this->catalogue();
        $this->toolTurn();

        $this->say('mtn bundles')->assertOk();

        $round2 = $this->sent()[1]->body();
        $this->assertStringContainsString('MTN 1GB', $round2, 'public catalogue data is what the answer is built from');
        foreach (['SECRET-SVC-9981', 'supplier_cost', 'external_mappings', 'Zulu', 'MTN Zulu Special'] as $private) {
            $this->assertStringNotContainsString($private, $round2, "{$private} is not public data");
        }
    }

    public function test_the_whole_turn_holds_one_slot_across_both_rounds_and_releases_it(): void
    {
        $this->catalogue();
        $held = [];

        $this->toolTurn(function (int $round) use (&$held) {
            foreach ([0, 1] as $slot) {
                $probe = Cache::lock("ai:slot:{$slot}", 5);
                $held[$round][$slot] = ! $probe->get();
                if (! $held[$round][$slot]) {
                    $probe->release();
                }
            }
        });

        $this->say('mtn bundles')->assertOk();

        $this->assertSame([1 => [0 => true, 1 => false], 2 => [0 => true, 1 => false]], $held, 'exactly one slot is held during each round of the turn');

        foreach ([0, 1] as $slot) {
            $probe = Cache::lock("ai:slot:{$slot}", 5);
            $this->assertTrue($probe->get(), "slot {$slot} is free again after the turn");
            $probe->release();
        }
    }

    public function test_a_single_slot_is_enough_for_a_two_round_turn_and_a_busy_engine_never_reaches_the_provider(): void
    {
        $this->catalogue();
        config(['ai.concurrency.max_in_flight' => 1]);
        $this->toolTurn();

        $this->say('mtn bundles')->assertOk();
        $this->assertCount(2, $this->sent(), 'the orchestrator borrowed the turn\'s slot instead of needing a second one');

        $this->toolTurn();
        $other = Cache::lock('ai:slot:0', 30);
        $this->assertTrue($other->get());

        $this->say('mtn bundles')->assertStatus(503);
        Http::assertNothingSent();
        $other->release();
    }

    public function test_an_exhausted_budget_stops_the_customer_turn_before_the_provider(): void
    {
        $this->catalogue();
        config(['ai.limits.surfaces.customer.daily_requests' => 0]);
        $this->toolTurn();

        $this->say('mtn bundles')->assertStatus(503);

        Http::assertNothingSent();
    }

    public function test_an_unapproved_external_processing_gate_blocks_the_public_assistant_before_the_provider(): void
    {
        $this->catalogue();
        config(['ai.external_processing.approved' => false]);
        $this->toolTurn();

        $response = $this->say('mtn bundles');

        $this->assertGreaterThanOrEqual(500, $response->status());
        Http::assertNothingSent();
        $this->assertStringNotContainsString('PRIV-REVIEW', $response->getContent());
    }

    public function test_the_provider_failure_message_shown_to_a_visitor_is_generic(): void
    {
        $this->catalogue();
        $this->fakeOpenAi(['error' => ['message' => 'Incorrect API key provided: sk-test-ZZZ', 'code' => 'invalid_api_key']], 401);

        $response = $this->say('mtn bundles');

        $this->assertGreaterThanOrEqual(500, $response->status());
        foreach (['sk-test', 'api key', 'Incorrect', 'openai', 'OpenAI', 'Bearer'] as $needle) {
            $this->assertStringNotContainsString($needle, $response->getContent());
        }
    }
}
