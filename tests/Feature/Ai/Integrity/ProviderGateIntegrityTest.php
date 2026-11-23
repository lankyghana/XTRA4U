<?php

namespace Tests\Feature\Ai\Integrity;

use App\Services\Ai\Data\AiMessage;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Exceptions\AiConfigurationException;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Integrity\IntegrityAnchor;
use App\Services\Ai\Integrity\IntegrityGuard;
use App\Services\Ai\Providers\OpenAiProvider;
use App\Services\Ai\Safety\KillSwitch;
use App\Services\Ai\Usage\BudgetGuard;
use App\Services\Ai\Usage\BudgetReservation;
use App\Services\Ai\Usage\PriceTable;
use App\Services\Ai\Usage\TokenEstimator;
use App\Services\Ai\Usage\UsageMeter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Ai\AiTestCase;
use Tests\Feature\Ai\Support\ConfiguresOpenAi;
use Tests\Feature\Ai\Support\FaultyAnchor;

/**
 * M5.7.2: the REAL OpenAI adapter (against Http::fake(), never a real key or network) must not transmit a single request unless the
 * independent anchor confirms the reservation, the anchor is healthy and reconciled, and the emergency stop is released.
 */
class ProviderGateIntegrityTest extends AiTestCase
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

    private function reserve(): BudgetReservation
    {
        $r = $this->request();
        $e = app(TokenEstimator::class);
        $in = $e->inputUpperBound($r);
        $out = $e->outputUpperBound($r);

        return app(BudgetGuard::class)->reserve(AiSurface::Vendor, $in + $out, true, app(PriceTable::class)->maxCostMicroUsd('openai', self::MODEL, $in, $out));
    }

    private function assertUnsent(BudgetReservation|null $reservation, string $why): void
    {
        try {
            app(OpenAiProvider::class)->send($reservation ? $this->request()->withReservation($reservation->id) : $this->request());
            $this->fail("the adapter transmitted although {$why}");
        } catch (AiProviderException|AiConfigurationException $e) {
            if ($e instanceof AiProviderException) {
                $this->assertFalse($e->mayHaveConsumedTokens(), 'a refusal before sending is certainly "not processed"');
            }
        }

        Http::assertNothingSent();
    }

    public function test_with_a_valid_anchor_reservation_exactly_one_request_is_sent(): void
    {
        $reservation = $this->reserve();

        app(OpenAiProvider::class)->send($this->request()->withReservation($reservation->id));

        Http::assertSentCount(1);
        $this->assertSame($reservation->id, array_key_first($this->anchor()->read()->reservations));
    }

    public function test_no_request_without_an_anchor_reservation_even_when_the_database_has_one(): void
    {
        $reservation = $this->reserve();

        // Remove the anchor's record of it (an anchor that never saw this reservation).
        $doc = $this->anchorDocument();
        unset($doc['reservations'][$reservation->id]);
        $this->rewriteAnchor($doc);

        $this->assertUnsent($reservation, 'the anchor does not hold the reservation');
    }

    public function test_no_request_when_the_ledger_row_lost_its_anchor_sequence(): void
    {
        $reservation = $this->reserve();
        DB::table(UsageMeter::LEDGER_TABLE)->where('id', $reservation->id)->update(['anchor_seq' => null]);

        $this->assertUnsent($reservation, 'the ledger row carries no anchor sequence');
    }

    public function test_no_request_with_a_different_anchor_sequence(): void
    {
        $reservation = $this->reserve();
        DB::table(UsageMeter::LEDGER_TABLE)->where('id', $reservation->id)->update(['anchor_seq' => 77]);

        $this->assertUnsent($reservation, 'the sequence numbers disagree');
    }

    public function test_no_request_while_the_anchor_is_unavailable(): void
    {
        $reservation = $this->reserve();

        $faulty = new FaultyAnchor($this->anchor());
        $faulty->failBefore['read'] = true;
        $this->app->instance(IntegrityAnchor::class, $faulty);

        $this->assertUnsent($reservation, 'the anchor is unavailable');
    }

    public function test_no_request_while_the_anchor_is_missing_or_corrupt(): void
    {
        $reservation = $this->reserve();
        $file = $this->anchorDir.DIRECTORY_SEPARATOR.'ai-anchor.json';
        $good = (string) file_get_contents($file);

        unlink($file);
        $this->assertUnsent($reservation, 'the anchor is missing');

        file_put_contents($file, substr($good, 0, 30));
        $this->assertUnsent($reservation, 'the anchor is corrupt');
    }

    public function test_no_request_while_a_reconciliation_is_pending(): void
    {
        $reservation = $this->reserve();
        $this->anchor()->setReconciliation('required', 'incomplete reconciliation');

        $this->assertUnsent($reservation, 'a reconciliation is incomplete');
    }

    public function test_no_request_while_the_shutdown_is_engaged(): void
    {
        $reservation = $this->reserve();
        app(KillSwitch::class)->engage('stop');

        $this->assertUnsent($reservation, 'the shutdown is engaged');
    }

    public function test_no_request_when_budget_integrity_cannot_be_established_and_the_failure_latches(): void
    {
        $reservation = $this->reserve();
        $older = $this->snapshotDatabase();
        // The database is restored to a state that never saw the reservation.
        DB::table(UsageMeter::LEDGER_TABLE)->delete();
        DB::table(UsageMeter::COUNTERS_TABLE)->delete();

        $this->assertUnsent($reservation, 'the reservation vanished from the database');
        $this->assertTrue(app(KillSwitch::class)->engaged());
        $this->assertSame('required', $this->anchor()->read()->reconciliation['status']);
        unset($older);
    }

    public function test_no_live_request_at_all_when_no_anchor_is_configured(): void
    {
        $this->removeAnchorDir();
        config(['ai.integrity.anchor.driver' => 'none']);
        $this->app->offsetUnset(IntegrityAnchor::class);

        $problems = app(OpenAiProvider::class)->configurationProblems(self::MODEL);
        $this->assertContains('the independent integrity anchor is not configured (AI_INTEGRITY_ANCHOR_DRIVER)', $problems);

        try {
            app(BudgetGuard::class)->reserve(AiSurface::Vendor, 100, true, 100);
            $this->fail('a live reservation needs an anchor');
        } catch (AiConfigurationException) {
        }

        // And a request that names a plain, un-anchored ledger row is refused too.
        $this->assertUnsent(null, 'there is no reservation and no anchor');
    }

    public function test_the_anchor_independence_attestation_is_required_for_live_use(): void
    {
        foreach ([
            ['ai.integrity.anchor.independence_attested' => false],
            ['ai.integrity.anchor.independence_reference' => ''],
            ['ai.integrity.anchor.independence_reference' => null],
            ['ai.integrity.anchor.key' => 'short'],
        ] as $change) {
            $this->configureOpenAi();
            config($change);

            $this->assertNotSame([], app(IntegrityGuard::class)->readinessProblems(), json_encode($change));
            $this->assertNotSame([], app(OpenAiProvider::class)->configurationProblems(self::MODEL), json_encode($change));
        }
    }

    public function test_the_full_orchestrated_path_sends_one_request_and_settles_in_both_stores(): void
    {
        $this->fakeOpenAi($this->okBody('answer'));

        $result = $this->engine()->run($this->req('Hello'));

        $this->assertSame('answer', $result->response->text);
        Http::assertSentCount(1);

        $row = DB::table(UsageMeter::LEDGER_TABLE)->first();
        $this->assertSame('settled', $row->state);
        $this->assertSame('settled', $this->anchor()->read()->reservation($row->id)['state']);
        $this->assertSame([], app(IntegrityGuard::class)->problems());
    }

    public function test_the_full_orchestrated_path_sends_nothing_when_the_anchor_is_down(): void
    {
        $faulty = new FaultyAnchor($this->anchor());
        $faulty->failBefore['reserve'] = true;
        $this->app->instance(IntegrityAnchor::class, $faulty);

        try {
            $this->engine()->run($this->req('Hello'));
            $this->fail('the request must be refused');
        } catch (\App\Services\Ai\Exceptions\AiException) {
        }

        Http::assertNothingSent();
        $this->assertSame(0, DB::table(UsageMeter::LEDGER_TABLE)->count());
    }

    /** @param array<string,mixed> $doc rewritten with a VALID signature (a tamper-proof anchor still accepts its own writer) */
    private function rewriteAnchor(array $doc): void
    {
        unset($doc['mac']);
        $doc['mac'] = hash_hmac('sha256', json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), self::ANCHOR_KEY);
        $this->writeAnchorDocumentRaw(json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
