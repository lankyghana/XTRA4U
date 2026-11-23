<?php

namespace Tests\Feature\Ai\Integrity;

use App\Services\Ai\Data\AiResponse;
use App\Services\Ai\Data\AiUsage;
use App\Services\Ai\Exceptions\AiConfigurationException;
use App\Services\Ai\Exceptions\AiIntegrityException;
use App\Services\Ai\Integrity\IntegrityGuard;
use App\Services\Ai\Safety\KillSwitch;
use App\Services\Ai\Usage\UsageMeter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * M5.7.2 recovery: what happens AFTER damage is detected. Nothing is reset, nothing is invented, the kill switch is never released
 * on the operator's behalf, and each trust-extending step needs its own explicit flag.
 */
class ReconciliationAndRecoveryTest extends IntegrityTestCase
{
    private function reconcile(array $options = []): int
    {
        return Artisan::call('ai:integrity', ['action' => 'reconcile', '--reason' => 'test recovery decision'] + $options);
    }

    /** @return array<string,int> the day's global counters */
    private function global(): array
    {
        $r = DB::table(UsageMeter::COUNTERS_TABLE)->where('day', $this->today())->where('scope', 'global')->first();

        return ['requests' => (int) $r->requests, 'tokens' => (int) $r->tokens, 'cost' => (int) $r->cost_micro_usd];
    }

    public function test_after_both_tables_are_lost_reconciliation_restores_the_spend_from_the_anchor_and_never_resets_it(): void
    {
        $this->turn();
        $this->turn();
        $spent = $this->global();
        $this->assertGreaterThan(0, $spent['cost']);

        DB::table(UsageMeter::LEDGER_TABLE)->delete();
        DB::table(UsageMeter::COUNTERS_TABLE)->delete();
        $this->assertRefused();

        $this->assertSame(0, $this->reconcile());

        $this->assertSame($spent, $this->global(), 'the spend came back exactly as the anchor recorded it: not zero, not estimated');
        $this->assertSame(2, $this->ledgerRows());
        $this->assertTrue(app(KillSwitch::class)->engaged(), 'reconciliation never releases the switch');
        $this->assertSame([], $this->guard()->problems());

        app(KillSwitch::class)->release('provider usage report matches');
        $this->turn();
        $this->assertSame(3, $this->global()['requests']);
    }

    public function test_after_an_older_backup_reconciliation_restores_every_lost_reservation_and_keeps_higher_charges(): void
    {
        $this->turn();
        $older = $this->snapshotDatabase();
        $this->turn();
        $this->turn();
        $spent = $this->global();

        $this->restoreDatabase($older);
        $this->assertRefused();

        $this->assertSame(0, $this->reconcile());

        $this->assertSame($spent, $this->global());
        $this->assertSame(3, $this->ledgerRows());
        $this->assertTrue(app(KillSwitch::class)->engaged());
    }

    public function test_reconciliation_does_not_invent_figures_it_restores_the_anchors_state_of_each_reservation(): void
    {
        $this->live->queue(new AiResponse('x', 'fakelive', 'test-model', new AiUsage(10, 5)));
        $this->turn();
        $settled = DB::table(UsageMeter::LEDGER_TABLE)->first();
        $this->assertSame('settled', $settled->state);

        DB::table(UsageMeter::LEDGER_TABLE)->delete();
        DB::table(UsageMeter::COUNTERS_TABLE)->delete();
        $this->assertRefused();
        $this->reconcile();

        $restored = DB::table(UsageMeter::LEDGER_TABLE)->first();
        $this->assertSame($settled->id, $restored->id);
        $this->assertSame('settled', $restored->state);
        $this->assertSame($settled->tokens, $restored->tokens);
        $this->assertSame($settled->cost_micro_usd, $restored->cost_micro_usd);
        $this->assertSame($settled->anchor_seq, $restored->anchor_seq);
    }

    public function test_a_database_restored_from_before_the_binding_needs_an_explicit_rebind(): void
    {
        $this->turn();
        DB::table(UsageMeter::FLAGS_TABLE)->where('name', IntegrityGuard::BINDING_FLAG)->delete();
        DB::table(UsageMeter::LEDGER_TABLE)->delete();
        DB::table(UsageMeter::COUNTERS_TABLE)->delete();

        $this->assertRefused();

        $this->assertSame(1, $this->reconcile(), 'not bound to the anchor: refused without --rebind-database');
        $this->assertStringContainsString('--rebind-database', Artisan::output());
        $this->assertSame(0, $this->ledgerRows(), 'and nothing was changed');

        $this->assertSame(0, $this->reconcile(['--rebind-database' => true]));
        $this->assertSame(1, $this->ledgerRows());
        $this->assertSame($this->anchor()->read()->anchorId, $this->guard()->boundTo());
    }

    public function test_an_older_anchor_needs_an_explicit_decision_before_the_database_rows_are_handed_to_it(): void
    {
        $this->turn();
        $old = (string) file_get_contents($this->anchorDir.DIRECTORY_SEPARATOR.'ai-anchor.json');
        $this->turn();
        $this->turn();
        $this->writeAnchorDocumentRaw($old);

        $this->assertRefused();

        $this->assertSame(1, $this->reconcile(), 'two database reservations are unknown to the anchor');
        $this->assertStringContainsString('--adopt-database-rows', Artisan::output());
        $this->assertSame('required', $this->anchor()->read()->reconciliation['status']);

        $this->assertSame(0, $this->reconcile(['--adopt-database-rows' => true]));
        $this->assertSame(3, count($this->anchor()->read()->reservations));
        $this->assertSame(3, $this->anchor()->read()->totals($this->today())['global']['requests']);
        $this->assertSame([], $this->guard()->problems());
    }

    public function test_a_failed_reconciliation_keeps_the_requirement_and_the_stop(): void
    {
        $this->turn();
        $this->turn();
        $older = $this->snapshotDatabase();
        $this->turn();
        $this->restoreDatabase($older);
        $this->assertRefused();

        // The anchor itself goes missing in the middle of the recovery: nothing may be declared fine.
        unlink($this->anchorDir.DIRECTORY_SEPARATOR.'ai-anchor.json');

        $this->assertSame(1, $this->reconcile());
        $this->assertTrue(app(KillSwitch::class)->engaged());
        $this->assertRefused();
    }

    public function test_reconcile_and_initialise_need_a_reason(): void
    {
        $this->assertSame(\Symfony\Component\Console\Command\Command::INVALID, Artisan::call('ai:integrity', ['action' => 'reconcile']));
        $this->assertSame(\Symfony\Component\Console\Command\Command::INVALID, Artisan::call('ai:integrity', ['action' => 'initialise', '--reason' => 'no']));
        $this->assertSame(\Symfony\Component\Console\Command\Command::INVALID, Artisan::call('ai:integrity', ['action' => 'wipe']));
    }

    // -------------------------------------------------------------------------- partial persistence

    public function test_an_anchor_acceptance_followed_by_a_database_failure_stays_charged_and_blocks_until_reconciled(): void
    {
        $this->turn();
        $before = $this->global();

        $disarm = $this->failDatabaseWhen('insert into ai_budget_reservations');
        $calls = $this->live->calls;
        try {
            $this->turn();
            $this->fail('the reservation could not be completed');
        } catch (AiConfigurationException) {
        }
        $disarm();

        $this->assertSame($calls, $this->live->calls, 'the provider was not called');
        $this->assertSame($before, $this->global(), 'the database charged nothing...');
        $this->assertSame(2, $this->anchor()->read()->totals($this->today())['global']['requests'], '...but the anchor keeps the reservation charged');

        // The next request is refused until an operator decides: the histories no longer agree.
        $this->assertRefused();
        $this->assertLatched();

        $this->assertSame(0, $this->reconcile());
        $this->assertSame(2, $this->global()['requests'], 'the orphan is now charged in the database too');

        app(KillSwitch::class)->release('reviewed');
        $this->turn();
        $this->assertSame(3, $this->global()['requests']);
    }

    public function test_an_anchor_that_applied_the_change_but_reported_failure_is_treated_as_accepted(): void
    {
        $faulty = new \Tests\Feature\Ai\Support\FaultyAnchor($this->anchor());
        $faulty->failAfter['reserve'] = true;
        $this->app->instance(\App\Services\Ai\Integrity\IntegrityAnchor::class, $faulty);

        $calls = $this->live->calls;
        try {
            $this->turn();
            $this->fail('the outcome was unknown');
        } catch (AiConfigurationException) {
        }

        $this->assertSame($calls, $this->live->calls, 'an unknown outcome never sends');
        $this->assertSame(1, $this->anchor()->read()->totals($this->today())['global']['requests'], 'it was in fact recorded: unknown outcomes are charged');
        $this->assertSame(0, $this->ledgerRows());
    }

    public function test_an_anchor_that_applied_a_settlement_but_reported_failure_leaves_the_database_charge_higher_and_consistent(): void
    {
        $this->live->queue(function () {
            $faulty = new \Tests\Feature\Ai\Support\FaultyAnchor($this->anchor());
            $faulty->failAfter['close'] = true;
            $this->app->instance(\App\Services\Ai\Integrity\IntegrityAnchor::class, $faulty);

            return new AiResponse('x', 'fakelive', 'test-model', new AiUsage(10, 5));
        });

        try {
            $this->turn();
            $this->fail('the settlement outcome was unknown');
        } catch (\Throwable) {
        }

        $row = DB::table(UsageMeter::LEDGER_TABLE)->first();
        $this->assertSame('open', $row->state, 'the database kept the full hold');
        $this->assertSame('settled', $this->anchor()->read()->reservation($row->id)['state'], 'the anchor holds the lower, final figure');

        $this->app->offsetUnset(\App\Services\Ai\Integrity\IntegrityAnchor::class);
        $this->assertSame([], $this->guard()->problems(), 'database >= anchor is the conservative direction and is accepted');
        $this->turn(); // and the system keeps working
    }

    public function test_a_provider_request_in_flight_during_a_restore_stays_charged_and_is_restored_open(): void
    {
        $older = $this->snapshotDatabase();

        $this->live->queue(function () use ($older) {
            // The provider is being called with a reservation held... and the database is restored underneath it.
            $this->restoreDatabase($older);

            return new AiResponse('answer', 'fakelive', 'test-model', new AiUsage(10, 5));
        });

        try {
            $this->turn();
            $this->fail('the settlement must be refused');
        } catch (AiConfigurationException) {
        }

        $this->assertLatched();
        $anchored = $this->anchor()->read();
        $this->assertSame(1, count($anchored->reservations));
        $this->assertSame('open', array_values($anchored->reservations)[0]['state'], 'the full hold stands: it is never released as unused');

        $this->assertSame(0, $this->reconcile());
        $row = DB::table(UsageMeter::LEDGER_TABLE)->first();
        $this->assertSame('open', $row->state);
        $this->assertGreaterThan(0, (int) $row->cost_micro_usd);
        $this->assertTrue(app(KillSwitch::class)->engaged());
    }

    // ------------------------------------------------------------------------------- initialise

    public function test_initialise_needs_explicit_trust_in_existing_history_and_in_replacing_a_bound_anchor(): void
    {
        $this->turn();

        $guard = $this->guard();

        try {
            $guard->initialise('again', true, false);
            $this->fail('already bound');
        } catch (AiIntegrityException $e) {
            $this->assertStringContainsString('already bound', $e->getMessage());
        }

        // Another directory: the anchor "was lost". Existing history is only accepted with an explicit flag.
        $second = sys_get_temp_dir().DIRECTORY_SEPARATOR.'xtra4u-anchor-second-'.bin2hex(random_bytes(4));
        mkdir($second);
        $this->configureAnchor($second);

        try {
            try {
                $guard->initialise('lost anchor', false, true);
                $this->fail('history needs acceptance');
            } catch (AiIntegrityException $e) {
                $this->assertStringContainsString('--accept-database-history', $e->getMessage());
            }

            $result = $guard->initialise('lost anchor', true, true);
            $this->assertSame(1, $result['records']);
            $this->assertTrue(app(KillSwitch::class)->engaged(), 'a new anchor starts engaged');
            $this->assertSame([], $guard->problems());
        } finally {
            foreach (glob($second.DIRECTORY_SEPARATOR.'*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($second);
        }
    }

    public function test_initialise_never_overwrites_an_existing_anchor_document(): void
    {
        $before = (string) file_get_contents($this->anchorDir.DIRECTORY_SEPARATOR.'ai-anchor.json');

        DB::table(UsageMeter::FLAGS_TABLE)->where('name', IntegrityGuard::BINDING_FLAG)->delete();

        try {
            $this->guard()->initialise('overwrite attempt', true, false);
            $this->fail('must refuse');
        } catch (AiIntegrityException) {
        }

        $this->assertSame($before, (string) file_get_contents($this->anchorDir.DIRECTORY_SEPARATOR.'ai-anchor.json'));
    }

    public function test_initialise_numbers_existing_unanchored_rows_and_seeds_the_anchor_from_them(): void
    {
        // History made BEFORE an anchor existed (offline mock runs): no anchor_seq.
        $this->removeAnchorDir();
        config(['ai.integrity.anchor.driver' => 'none', 'ai.provider' => 'mock', 'ai.default_model' => null]);
        $this->app->offsetUnset(\App\Services\Ai\Integrity\IntegrityAnchor::class);
        $this->useProvider($this->mockProvider());
        $this->prepareDurableAiState();
        $this->engine()->run($this->req());
        $this->engine()->run($this->req());
        $this->assertSame(2, DB::table(UsageMeter::LEDGER_TABLE)->whereNull('anchor_seq')->count());

        $this->anchorDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'xtra4u-anchor-'.bin2hex(random_bytes(6));
        mkdir($this->anchorDir);
        $this->configureAnchor($this->anchorDir);

        $result = $this->guard()->initialise('first anchor', true, false);

        $this->assertSame(2, $result['records']);
        $this->assertSame([1, 2], DB::table(UsageMeter::LEDGER_TABLE)->orderBy('anchor_seq')->pluck('anchor_seq')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(2, $this->anchor()->read()->totals($this->today())['global']['requests']);
        $this->assertSame([], $this->guard()->problems());
        $this->assertTrue(app(KillSwitch::class)->engaged());
    }

    public function test_initialise_refuses_a_database_that_is_not_self_consistent(): void
    {
        $this->removeAnchorDir();
        config(['ai.integrity.anchor.driver' => 'none', 'ai.provider' => 'mock', 'ai.default_model' => null]);
        $this->app->offsetUnset(\App\Services\Ai\Integrity\IntegrityAnchor::class);
        $this->useProvider($this->mockProvider());
        $this->prepareDurableAiState();
        $this->engine()->run($this->req());
        DB::table(UsageMeter::COUNTERS_TABLE)->update(['requests' => 0]);

        $this->anchorDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'xtra4u-anchor-'.bin2hex(random_bytes(6));
        mkdir($this->anchorDir);
        $this->configureAnchor($this->anchorDir);

        $this->expectException(AiIntegrityException::class);
        $this->expectExceptionMessage('not self-consistent');
        $this->guard()->initialise('bad seed', true, false);
    }

    public function test_application_restart_after_recovery_changes_nothing(): void
    {
        $this->turn();
        DB::table(UsageMeter::LEDGER_TABLE)->delete();
        DB::table(UsageMeter::COUNTERS_TABLE)->delete();
        $this->assertRefused();
        $this->reconcile();
        app(KillSwitch::class)->release('recovered');

        Cache::flush();
        foreach ([KillSwitch::class, IntegrityGuard::class, \App\Services\Ai\Usage\BudgetGuard::class, \App\Services\Ai\AiOrchestrator::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }

        $this->assertSame([], $this->guard()->problems());
        $this->turn();
        $this->assertSame(2, $this->global()['requests']);
    }

    public function test_status_and_verify_are_read_only_and_describe_the_damage(): void
    {
        $this->turn();
        $older = $this->snapshotDatabase();
        $this->turn();
        $this->restoreDatabase($older);

        $rowsBefore = DB::table(UsageMeter::LEDGER_TABLE)->count();
        $anchorBefore = (string) file_get_contents($this->anchorDir.DIRECTORY_SEPARATOR.'ai-anchor.json');

        $this->assertSame(0, Artisan::call('ai:integrity', ['action' => 'status']));
        $this->assertStringContainsString('MISMATCH', Artisan::output());
        $this->assertSame(1, Artisan::call('ai:integrity', ['action' => 'verify']));

        $this->assertSame($rowsBefore, DB::table(UsageMeter::LEDGER_TABLE)->count());
        $this->assertSame($anchorBefore, (string) file_get_contents($this->anchorDir.DIRECTORY_SEPARATOR.'ai-anchor.json'), 'reading never changes the anchor');
    }
}
