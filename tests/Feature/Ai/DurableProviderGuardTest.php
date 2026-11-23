<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Data\AiMessage;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Providers\OpenAiProvider;
use App\Services\Ai\Safety\KillSwitch;
use App\Services\Ai\Usage\BudgetGuard;
use App\Services\Ai\Usage\BudgetReservation;
use App\Services\Ai\Usage\PriceTable;
use App\Services\Ai\Usage\TokenEstimator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Ai\Support\ConfiguresOpenAi;

/**
 * M5.7.1: no external provider request without a valid durable reservation and a released kill switch. These tests drive the
 * REAL OpenAI adapter against Http::fake() (no real key, no network): the adapter itself, not only the orchestrator in front
 * of it, must refuse to transmit.
 */
class DurableProviderGuardTest extends AiTestCase
{
    use ConfiguresOpenAi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureOpenAi();
        $this->fakeOpenAi($this->okBody());
    }

    private function request(): AiRequest
    {
        return (new AiRequest(AiSurface::Vendor, 'vendor:1', [AiMessage::user('hi')]))->resolved(self::MODEL, 512, 10);
    }

    /** A real reservation in the durable ledger for the request above. */
    private function reserve(AiSurface $surface = AiSurface::Vendor): BudgetReservation
    {
        $r = $this->request();
        $e = app(TokenEstimator::class);
        $in = $e->inputUpperBound($r);
        $out = $e->outputUpperBound($r);
        $cost = app(PriceTable::class)->maxCostMicroUsd('openai', self::MODEL, $in, $out);

        return app(BudgetGuard::class)->reserve($surface, $in + $out, true, $cost);
    }

    private function assertRefusedUnsent(AiRequest $request): void
    {
        try {
            app(OpenAiProvider::class)->send($request);
            $this->fail('the adapter must refuse to transmit');
        } catch (AiProviderException $e) {
            $this->assertFalse($e->mayHaveConsumedTokens(), 'a refusal before sending is certainly "not processed"');
            $this->assertFalse($e->isRetryable());
        }

        Http::assertNothingSent();
    }

    public function test_a_request_without_a_reservation_is_never_transmitted(): void
    {
        $this->assertRefusedUnsent($this->request());
    }

    public function test_a_made_up_reservation_id_is_never_transmitted(): void
    {
        $this->assertRefusedUnsent($this->request()->withReservation('01hzzzzzzzzzzzzzzzzzzzzzzz'));
    }

    public function test_a_closed_reservation_cannot_pay_for_a_request(): void
    {
        $r = $this->reserve();
        app(BudgetGuard::class)->settle($r, 10, 5);

        $this->assertRefusedUnsent($this->request()->withReservation($r->id));
    }

    public function test_a_reservation_of_another_surface_cannot_pay_for_a_request(): void
    {
        $r = $this->reserve(AiSurface::Customer);

        $this->assertRefusedUnsent($this->request()->withReservation($r->id)); // the request is for the vendor surface
    }

    public function test_an_engaged_kill_switch_stops_a_request_that_does_hold_a_reservation(): void
    {
        $r = $this->reserve();
        app(KillSwitch::class)->engage();

        $this->assertRefusedUnsent($this->request()->withReservation($r->id));
    }

    public function test_a_disabled_engine_stops_a_request_that_does_hold_a_reservation(): void
    {
        $r = $this->reserve();
        config(['ai.enabled' => false]);

        $this->assertRefusedUnsent($this->request()->withReservation($r->id));
    }

    public function test_an_unreadable_ledger_stops_the_request(): void
    {
        $r = $this->reserve();
        Schema::drop('ai_budget_reservations');

        $this->assertRefusedUnsent($this->request()->withReservation($r->id));
    }

    public function test_a_valid_open_reservation_is_the_only_thing_that_lets_it_through(): void
    {
        $r = $this->reserve();

        $response = app(OpenAiProvider::class)->send($this->request()->withReservation($r->id));

        $this->assertSame('Hello from the model', $response->text);
        $this->assertCount(1, $this->sent());
    }

    public function test_through_the_engine_every_provider_request_is_covered_by_an_open_ledger_row_at_the_moment_it_is_sent(): void
    {
        $seen = [];
        $this->fakeOpenAi(function ($request) use (&$seen) {
            $row = DB::table('ai_budget_reservations')->first();
            $seen[] = [
                'rows' => DB::table('ai_budget_reservations')->count(),
                'state' => $row?->state,
                'held_tokens' => (int) $row?->tokens,
                'counter_tokens' => (int) DB::table('ai_usage_counters')->where('scope', 'global')->value('tokens'),
                'counter_cost' => (int) DB::table('ai_usage_counters')->where('scope', 'global')->value('cost_micro_usd'),
            ];

            return Http::response($this->okBody(), 200);
        });

        $this->engine()->run(new AiRequest(AiSurface::Vendor, 'vendor:1', [AiMessage::user('Hello')]));

        $this->assertCount(1, $seen, 'one provider request');
        $this->assertSame(1, $seen[0]['rows'], 'its reservation already existed');
        $this->assertSame(BudgetGuard::OPEN, $seen[0]['state'], 'and was open');
        $this->assertGreaterThan(512, $seen[0]['held_tokens'], 'the full worst case (input bound + output cap) was held before the call');
        $this->assertSame($seen[0]['held_tokens'], $seen[0]['counter_tokens'], 'and counted in the durable counters');
        $this->assertGreaterThan(0, $seen[0]['counter_cost'], 'with its maximum cost');

        $this->assertSame(BudgetGuard::SETTLED, DB::table('ai_budget_reservations')->value('state'), 'settled afterwards');
        $this->assertSame(140, (int) DB::table('ai_usage_counters')->where('scope', 'global')->value('tokens'));
    }

    public function test_the_adapter_checks_the_guards_before_it_builds_a_payload_or_reads_the_key(): void
    {
        $source = (string) file_get_contents(app_path('Services/Ai/Providers/OpenAiProvider.php'));
        $send = substr($source, (int) strpos($source, 'public function send('));

        $guard = strpos($send, '$this->assertAuthorised($request)');
        $this->assertNotFalse($guard);
        $this->assertLessThan(strpos($send, '$this->payload('), $guard);
        $this->assertLessThan(strpos($send, "config('ai.providers.openai.api_key')"), $guard);
        $this->assertLessThan(strpos($send, 'Http::'), $guard);

        // And it is the only place in the AI engine that talks HTTP at all.
        foreach (glob(app_path('Services/Ai/{,*/,*/*/}*.php'), GLOB_BRACE) as $file) {
            if (basename($file) !== 'OpenAiProvider.php') {
                $this->assertDoesNotMatchRegularExpression('/\bHttp::|Illuminate\\\\Http\\\\Client|curl_init/', (string) file_get_contents($file), $file);
            }
        }
    }
}
