<?php

namespace Tests\Feature\Ai\Integrity;

use App\Services\Ai\Data\AiResponse;
use App\Services\Ai\Data\AiUsage;
use App\Services\Ai\Exceptions\AiBudgetExceededException;
use App\Services\Ai\Exceptions\AiIntegrityException;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Integrity\IntegrityAnchor;
use App\Services\Ai\Integrity\IntegrityGuard;
use App\Services\Ai\Safety\KillSwitch;
use App\Services\Ai\Usage\UsageMeter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Ai\Support\FaultyAnchor;

/**
 * M5.7.2: the emergency stop is also kept in the independent anchor, so restoring an older database cannot silently give AI back,
 * and every way of recovering is an explicit operator action that never releases the switch by itself.
 */
class KillSwitchRecoveryTest extends IntegrityTestCase
{
    private function ks(): KillSwitch
    {
        return app(KillSwitch::class);
    }

    public function test_it_is_released_in_both_stores_with_the_same_generation_after_setup(): void
    {
        $this->assertFalse($this->ks()->engaged());

        $status = $this->ks()->status();
        $this->assertTrue($status['anchor']['agrees']);
        $this->assertSame($status['anchor']['generation'], $status['anchor']['database_generation']);
    }

    public function test_restoring_an_older_database_that_says_released_cannot_reactivate_ai(): void
    {
        $olderReleased = $this->snapshotDatabase();

        $this->ks()->engage('incident: unexpected spend');
        $this->assertTrue($this->ks()->engaged());

        $this->restoreDatabase($olderReleased); // the database now says `released` again

        $this->assertSame('released', DB::table(UsageMeter::FLAGS_TABLE)->where('name', 'kill_switch')->value('value'));
        $this->assertTrue($this->ks()->engaged(), 'the anchor remembers the shutdown');
        $this->assertRefused('after the older database was restored');
        $this->assertFalse($this->ks()->engineEnabled());
    }

    public function test_a_restore_that_matches_the_anchors_state_but_not_its_generation_is_still_engaged(): void
    {
        $olderReleased = $this->snapshotDatabase();

        $this->ks()->engage('first incident');
        $this->ks()->release('resolved');
        $this->assertFalse($this->ks()->engaged());

        // Both say `released`, but the restored database is two generations behind: it proves nothing about the CURRENT decision.
        $this->restoreDatabase($olderReleased);

        $this->assertTrue($this->ks()->engaged());
        $this->assertRefused();
    }

    public function test_a_restored_older_engaged_state_cannot_release_either(): void
    {
        $this->ks()->engage('pause');
        $engagedSnapshot = $this->snapshotDatabase();
        $this->ks()->release('resume');
        $this->assertFalse($this->ks()->engaged());

        $this->restoreDatabase($engagedSnapshot);
        $this->assertTrue($this->ks()->engaged());
    }

    public function test_a_missing_switch_row_a_missing_generation_a_missing_anchor_or_a_disagreement_each_fail_closed(): void
    {
        $file = $this->anchorDir.DIRECTORY_SEPARATOR.'ai-anchor.json';
        $backup = (string) file_get_contents($file);
        $flags = $this->snapshotDatabase()[UsageMeter::FLAGS_TABLE];

        $cases = [
            'no switch row' => fn () => DB::table(UsageMeter::FLAGS_TABLE)->where('name', 'kill_switch')->delete(),
            'no generation row' => fn () => DB::table(UsageMeter::FLAGS_TABLE)->where('name', IntegrityGuard::GENERATION_FLAG)->delete(),
            'database generation behind' => fn () => DB::table(UsageMeter::FLAGS_TABLE)->where('name', IntegrityGuard::GENERATION_FLAG)->update(['value' => '1']),
            'database generation ahead' => fn () => DB::table(UsageMeter::FLAGS_TABLE)->where('name', IntegrityGuard::GENERATION_FLAG)->update(['value' => '999']),
            'garbage value' => fn () => DB::table(UsageMeter::FLAGS_TABLE)->where('name', 'kill_switch')->update(['value' => 'RELEASED ']),
            'anchor missing' => fn () => unlink($file),
            'anchor corrupt' => fn () => file_put_contents($file, '{"broken'),
            'reconciliation required' => fn () => $this->anchor()->setReconciliation('required', 'test'),
        ];

        foreach ($cases as $label => $damage) {
            DB::table(UsageMeter::FLAGS_TABLE)->delete();
            foreach ($flags as $row) {
                DB::table(UsageMeter::FLAGS_TABLE)->insert($row);
            }
            file_put_contents($file, $backup);
            $this->assertFalse($this->ks()->engaged(), "{$label}: baseline must be released");

            $damage();

            $this->assertTrue($this->ks()->engaged(), $label);
            $this->assertFalse($this->ks()->engineEnabled(), $label);
        }
    }

    public function test_cache_clear_and_a_restart_cannot_release_a_shutdown(): void
    {
        $this->ks()->engage('shutdown');

        Cache::flush();
        $this->artisan('cache:clear')->assertExitCode(0);
        $this->app->forgetInstance(KillSwitch::class);
        $this->app->forgetInstance(IntegrityGuard::class);

        $this->assertTrue($this->ks()->engaged());
        $this->assertRefused();
    }

    public function test_a_shutdown_during_an_active_request_stops_the_next_one_and_does_not_lose_the_charge(): void
    {
        $this->live->queue(function () {
            $this->ks()->engage('shutdown while a request is in flight');

            return new AiResponse('answer', 'fakelive', 'test-model', new AiUsage(10, 5));
        });

        $this->turn(); // the request already in flight completes and is settled
        $this->assertSame(1, $this->live->calls);
        $this->assertSame('settled', DB::table(UsageMeter::LEDGER_TABLE)->value('state'));

        $this->assertRefused('after the shutdown');
        $this->assertSame(1, $this->live->calls);
    }

    public function test_a_shutdown_during_a_retry_stops_the_retry_and_keeps_the_first_attempt_conservative(): void
    {
        config(['ai.request.max_retries' => 1]);

        $this->live->queue(
            function () {
                $this->ks()->engage('shutdown between attempts');

                throw AiProviderException::unavailable('refused', requestSent: false);
            },
            new AiResponse('must never be requested', 'fakelive', 'test-model', new AiUsage(1, 1)),
        );

        try {
            $this->turn();
            $this->fail('the request must fail');
        } catch (AiProviderException) {
            // the original provider error, not a retry
        }

        $this->assertSame(1, $this->live->calls, 'no second attempt after the shutdown');
        $this->assertSame(1, $this->ledgerRows(), 'and no second reservation');
    }

    public function test_recovery_commands_never_release_the_kill_switch(): void
    {
        $this->turn();
        DB::table(UsageMeter::LEDGER_TABLE)->delete();
        DB::table(UsageMeter::COUNTERS_TABLE)->delete();
        $this->assertRefused();

        $this->assertSame(0, Artisan::call('ai:integrity', ['action' => 'reconcile', '--reason' => 'restored from the anchor after the incident']));

        $this->assertTrue($this->ks()->engaged(), 'reconciling must not release it');
        $this->assertSame('ok', $this->anchor()->read()->reconciliation['status']);
        $this->assertRefused('until an operator releases');

        $this->ks()->release('reconciled with the provider usage report');
        $this->turn();
        $this->assertSame(2, $this->live->calls);
    }

    public function test_release_is_refused_while_the_database_disagrees_with_the_anchor_or_a_reconciliation_is_pending(): void
    {
        $this->turn();
        $older = $this->snapshotDatabase();
        $this->turn();
        $this->restoreDatabase($older);

        $this->assertRefused(); // detects, latches
        $this->assertTrue($this->ks()->engaged());

        $this->expectException(AiIntegrityException::class);
        try {
            $this->ks()->release('trying to bypass');
        } finally {
            $this->assertTrue($this->ks()->engaged());
        }
    }

    public function test_the_release_command_reports_the_refusal_and_exits_nonzero(): void
    {
        $this->anchor()->setReconciliation('required', 'test');

        $code = Artisan::call('ai:kill-switch', ['action' => 'release']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('cannot be released', Artisan::output());
        $this->assertTrue($this->ks()->engaged());
    }

    public function test_releasing_writes_the_anchor_first_so_a_half_finished_release_leaves_ai_off(): void
    {
        $this->ks()->engage('pause');

        // The anchor accepts the release but the database write then fails.
        $disarm = $this->failDatabaseWhen('insert into ai_runtime_flags');
        try {
            $this->ks()->release('resume');
            $this->fail('the release did not complete');
        } catch (\Throwable) {
        }
        $disarm();

        $this->assertSame('released', $this->anchor()->read()->kill['state'], 'the anchor was released first');
        $this->assertTrue($this->ks()->engaged(), 'but the database still says engaged: AI stays off');
        $this->assertRefused();
    }

    public function test_an_anchor_that_fails_to_release_changes_nothing(): void
    {
        $this->ks()->engage('pause');

        $faulty = new FaultyAnchor($this->anchor());
        $faulty->failBefore['setKillSwitch'] = true;
        $this->app->instance(IntegrityAnchor::class, $faulty);

        try {
            $this->ks()->release('resume');
            $this->fail('expected a refusal');
        } catch (AiIntegrityException) {
        }

        $this->assertTrue($this->ks()->engaged());
        $this->assertSame('engaged', DB::table(UsageMeter::FLAGS_TABLE)->where('name', 'kill_switch')->value('value'));
    }

    public function test_engaging_while_the_anchor_is_down_still_engages_the_database_and_reports_the_failure(): void
    {
        $faulty = new FaultyAnchor($this->anchor());
        $faulty->failBefore['setKillSwitch'] = true;
        $this->app->instance(IntegrityAnchor::class, $faulty);

        $this->assertSame(1, Artisan::call('ai:kill-switch', ['action' => 'engage', '--reason' => 'emergency']));
        $this->assertStringContainsString('anchor could not be updated', Artisan::output());

        $this->assertSame('engaged', DB::table(UsageMeter::FLAGS_TABLE)->where('name', 'kill_switch')->value('value'));
        $this->assertTrue($this->ks()->engaged());
    }

    public function test_the_status_command_shows_the_independent_state_and_whether_the_stores_agree(): void
    {
        Artisan::call('ai:kill-switch', ['action' => 'status']);
        $out = Artisan::output();

        $this->assertStringContainsString('independent anchor: released', $out);
        $this->assertStringContainsString('in agreement', $out);
    }

    public function test_releasing_does_not_bypass_the_budget_gate(): void
    {
        config(['ai.limits.global.daily_requests' => 1]);

        $this->ks()->engage('pause');
        $this->ks()->release('resume');

        $this->turn();
        try {
            $this->turn();
            $this->fail('the exhausted budget must still refuse');
        } catch (AiBudgetExceededException) {
        }
        $this->assertSame(1, $this->live->calls);
    }

    public function test_without_any_anchor_the_database_switch_behaves_exactly_as_before(): void
    {
        $this->removeAnchorDir();
        config(['ai.integrity.anchor.driver' => 'none']);
        $this->app->offsetUnset(IntegrityAnchor::class);

        $this->ks()->engage('x');
        $this->assertTrue($this->ks()->engaged());
        $this->ks()->release('y');
        $this->assertFalse($this->ks()->engaged());
        $this->assertFalse($this->ks()->status()['anchor']['configured']);
    }
}
