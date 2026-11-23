<?php

namespace Tests\Feature\Ai\Integrity;

use App\Services\Ai\Safety\KillSwitch;
use App\Services\Ai\Usage\UsageMeter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Ai\Support\FaultyAnchor;

/**
 * M5.7.2 threat model: the application database moves BACKWARDS or loses its budget history while the independent anchor does not.
 *
 * Every scenario has the same shape: spend some budget, damage or roll back the database, then ask for another provider
 * request. It must be refused BEFORE the provider is reached, the emergency stop must latch, and a reconciliation must be required.
 */
class DatabaseRollbackDetectionTest extends IntegrityTestCase
{
    private function spend(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->turn("request number {$i}");
        }
    }

    public function test_control_without_an_anchor_losing_both_tables_is_invisible(): void
    {
        // The M5.7.1 situation, kept as a regression: with no anchor, deleting counters AND ledger together looks like a quiet day.
        $this->removeAnchorDir();
        config(['ai.integrity.anchor.driver' => 'none', 'ai.provider' => 'mock', 'ai.default_model' => null]);
        $this->app->forgetInstance(\App\Services\Ai\Providers\AiProviderManager::class);
        $this->useProvider($this->mockProvider());
        $this->prepareDurableAiState();

        $this->engine()->run($this->req());
        $this->assertSame(1, $this->counter('global', 'requests'));

        DB::table(UsageMeter::LEDGER_TABLE)->delete();
        DB::table(UsageMeter::COUNTERS_TABLE)->delete();

        $this->engine()->run($this->req());
        $this->assertSame(1, $this->counter('global', 'requests'), 'the lost spend is simply gone: nothing inside the database can tell');
    }

    public function test_deleting_both_budget_tables_is_detected(): void
    {
        $this->spend(3);
        $this->assertSame(3, $this->live->calls);

        DB::table(UsageMeter::LEDGER_TABLE)->delete();
        DB::table(UsageMeter::COUNTERS_TABLE)->delete();

        $this->assertRefused('after both tables were deleted');
        $this->assertLatched();
        $this->assertSame(0, $this->ledgerRows(), 'nothing was invented to fill the gap');
        $this->assertSame(0, $this->counter('global', 'requests'), 'and no figure was reset or recreated as usage');
    }

    public function test_truncating_both_budget_tables_is_detected(): void
    {
        $this->spend(2);

        DB::table(UsageMeter::LEDGER_TABLE)->truncate();
        DB::table(UsageMeter::COUNTERS_TABLE)->truncate();

        $this->assertRefused('after both tables were truncated');
        $this->assertLatched();
    }

    public function test_deleting_only_the_ledger_is_detected(): void
    {
        $this->spend(2);
        DB::table(UsageMeter::LEDGER_TABLE)->delete();

        $this->assertRefused();
        $this->assertLatched();
    }

    public function test_deleting_only_the_counters_is_detected(): void
    {
        $this->spend(2);
        DB::table(UsageMeter::COUNTERS_TABLE)->delete();

        $this->assertRefused();
        $this->assertSame(2, $this->ledgerRows());
    }

    public function test_restoring_an_older_database_snapshot_is_detected(): void
    {
        $this->spend(1);
        $older = $this->snapshotDatabase();
        $this->spend(3);

        // A restore of the whole database: counters, ledger AND flags all go back together, so every check INSIDE the database passes.
        $this->restoreDatabase($older);
        $this->assertSame([], app(\App\Services\Ai\Usage\BudgetGuard::class)->integrityProblems(), 'internally consistent: the pre-anchor invariant cannot see it');

        $this->assertRefused('after an older backup was restored');
        $this->assertLatched();
        $this->assertSame(1, $this->ledgerRows());
        $this->assertSame(4, $this->anchor()->read()->totals($this->today())['global']['requests'], 'the anchor still remembers every request');
    }

    public function test_a_point_in_time_recovery_that_drops_only_the_latest_reservation_is_detected(): void
    {
        $this->spend(2);
        $beforeLast = $this->snapshotDatabase();
        $this->spend(1);

        $this->restoreDatabase($beforeLast);

        $this->assertRefused('after the newest reservation was lost');
        $this->assertLatched();
    }

    public function test_a_ledger_restored_without_its_counters_is_detected(): void
    {
        $this->spend(1);
        $older = $this->snapshotDatabase();
        $this->spend(2);

        DB::table(UsageMeter::LEDGER_TABLE)->delete();
        foreach ($older[UsageMeter::LEDGER_TABLE] as $row) {
            DB::table(UsageMeter::LEDGER_TABLE)->insert($row);
        }

        $this->assertRefused();
    }

    public function test_counters_restored_without_their_ledger_are_detected(): void
    {
        $this->spend(1);
        $older = $this->snapshotDatabase();
        $this->spend(2);

        DB::table(UsageMeter::COUNTERS_TABLE)->delete();
        foreach ($older[UsageMeter::COUNTERS_TABLE] as $row) {
            DB::table(UsageMeter::COUNTERS_TABLE)->insert($row);
        }

        $this->assertRefused();
    }

    public function test_a_sequence_rolled_back_in_the_ledger_is_detected(): void
    {
        $this->spend(3);

        $newest = DB::table(UsageMeter::LEDGER_TABLE)->orderByDesc('anchor_seq')->first();
        DB::table(UsageMeter::LEDGER_TABLE)->where('id', $newest->id)->update(['anchor_seq' => 0]);

        $this->assertRefused('a ledger whose highest sequence number went backwards');
        $this->assertLatched();
    }

    public function test_a_charge_lowered_in_the_database_is_detected_but_a_raised_one_is_tolerated(): void
    {
        $this->spend(2);

        // Higher in the database than in the anchor is the conservative direction: tolerated.
        $row = DB::table(UsageMeter::LEDGER_TABLE)->orderBy('anchor_seq')->first();
        DB::table(UsageMeter::LEDGER_TABLE)->where('id', $row->id)->update(['tokens' => $row->tokens + 1000]);
        foreach (['global', 'surface.vendor'] as $scope) {
            DB::table(UsageMeter::COUNTERS_TABLE)->where('day', $this->today())->where('scope', $scope)->update(['tokens' => DB::raw('tokens + 1000')]);
        }
        $this->turn();

        // Lower is not: that is usage disappearing.
        DB::table(UsageMeter::LEDGER_TABLE)->where('id', $row->id)->update(['tokens' => 0]);
        foreach (['global', 'surface.vendor'] as $scope) {
            DB::table(UsageMeter::COUNTERS_TABLE)->where('day', $this->today())->where('scope', $scope)->update(['tokens' => DB::raw('tokens - '.($row->tokens + 1000))]);
        }

        $this->assertRefused();
        $this->assertLatched();
    }

    public function test_a_replayed_older_anchor_document_is_detected_as_the_anchor_being_behind(): void
    {
        $this->spend(1);
        $old = (string) file_get_contents($this->anchorDir.DIRECTORY_SEPARATOR.'ai-anchor.json');
        $this->spend(2);

        $this->writeAnchorDocumentRaw($old); // an authentic but STALE checkpoint (it passes its own MAC)

        $this->assertRefused('with an older anchor in place');
        $this->assertTrue(app(KillSwitch::class)->engaged());
        $this->assertStringContainsString('ahead of the anchor', implode(' ', $this->guard()->problems()));
    }

    public function test_a_missing_anchor_blocks_and_never_reads_as_an_empty_history(): void
    {
        $this->spend(2);
        unlink($this->anchorDir.DIRECTORY_SEPARATOR.'ai-anchor.json');

        $this->assertRefused();
        $this->assertTrue(app(KillSwitch::class)->engaged());
        $this->assertSame(2, $this->ledgerRows(), 'the database history is untouched');
    }

    public function test_a_corrupted_or_tampered_anchor_blocks(): void
    {
        $this->spend(1);
        $doc = (string) file_get_contents($this->anchorDir.DIRECTORY_SEPARATOR.'ai-anchor.json');

        foreach ([
            'truncated' => substr($doc, 0, intdiv(strlen($doc), 2)),
            'garbage' => 'not json at all',
            'emptied' => '',
            'tampered' => str_replace('"seq":1', '"seq":0', $doc),
            'binary' => random_bytes(64),
        ] as $label => $content) {
            $this->writeAnchorDocumentRaw($content);

            $this->assertRefused("with a {$label} anchor");
            $this->assertTrue(app(KillSwitch::class)->engaged(), $label);
            $this->assertFalse($this->guard()->readinessProblems() === [], $label);
        }
    }

    public function test_an_anchor_with_the_wrong_key_does_not_authenticate(): void
    {
        $this->spend(1);
        config(['ai.integrity.anchor.key' => 'a-completely-different-key-0123456789abcdef']);

        $this->assertRefused();
    }

    public function test_an_unavailable_anchor_refuses_every_request_without_latching_and_recovers_by_itself(): void
    {
        $this->spend(1);

        $faulty = new FaultyAnchor($this->anchor());
        $faulty->failBefore['read'] = true;
        $this->app->instance(\App\Services\Ai\Integrity\IntegrityAnchor::class, $faulty);

        $this->assertRefused('while the anchor is down');
        $this->assertRefused('and again');

        // An outage is not evidence of damage: nothing was latched, so the anchor coming back needs no operator.
        $this->app->offsetUnset(\App\Services\Ai\Integrity\IntegrityAnchor::class);
        $this->assertSame('ok', $this->anchor()->read()->reconciliation['status']);

        $this->turn();
        $this->assertSame(2, $this->live->calls);
    }

    public function test_the_anchor_unavailable_at_reservation_time_means_no_request_and_no_database_charge(): void
    {
        $this->spend(1);

        $faulty = new FaultyAnchor($this->anchor());
        $faulty->failBefore['reserve'] = true;
        $this->app->instance(\App\Services\Ai\Integrity\IntegrityAnchor::class, $faulty);

        $before = $this->counter('global', 'requests');
        $this->assertRefused();
        $this->assertSame($before, $this->counter('global', 'requests'), 'the counters rolled back with the failed reservation');
        $this->assertSame(1, $this->ledgerRows());
    }

    public function test_cache_clear_and_a_restart_do_not_hide_or_clear_a_detected_rollback(): void
    {
        $this->spend(2);
        $this->restoreDatabase(['ai_usage_counters' => [], 'ai_budget_reservations' => [], 'ai_runtime_flags' => $this->snapshotDatabase()['ai_runtime_flags']]);

        $this->assertRefused();

        Cache::flush();
        $this->app->forgetInstance(KillSwitch::class);
        $this->app->forgetInstance(\App\Services\Ai\Integrity\IntegrityGuard::class);

        $this->assertRefused('after a cache clear and a restart');
        $this->assertLatched();
    }

    public function test_detection_does_not_make_the_exception_reveal_internals(): void
    {
        $this->spend(1);
        DB::table(UsageMeter::LEDGER_TABLE)->delete();
        DB::table(UsageMeter::COUNTERS_TABLE)->delete();

        $e = $this->assertRefused();

        $this->assertSame('ai_misconfigured', $e->errorCode());
        $this->assertSame('The assistant is not available right now.', $e->userMessage());
    }
}
