<?php

namespace Tests\Feature\Ai\Integrity;

use App\Services\Ai\Integrity\IntegrityAnchor;
use App\Services\Ai\Preflight\AiPreflight;
use App\Services\Ai\Usage\UsageMeter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Ai\AiTestCase;
use Tests\Feature\Ai\Support\ConfiguresOpenAi;

/**
 * `ai:preflight --live` must name the missing independent integrity protection and the unconfirmed provider-side spending cap, and
 * must never turn green on a same-database guarantee. Nothing here calls a provider.
 */
class IntegrityPreflightTest extends AiTestCase
{
    use ConfiguresOpenAi;

    private function ready(): void
    {
        $this->configureOpenAi();
        config([
            'trustedproxy.proxies' => ['10.1.0.0/16'],
            'trustedproxy.rejected' => 0,
            'ai.operations.customer_worker_capacity_confirmed' => true,
            'ai.operations.provider_spending_cap_confirmed' => true,
            'ai.operations.provider_spending_cap_reference' => 'CHG-2026-0042',
            'ai.rate_limits.customer.per_minute' => 5,
            'ai.rate_limits.vendor.per_minute' => 5,
            'ai.rate_limits.developer.per_minute' => 5,
        ]);
    }

    /** @return array<string,array{status:string,detail:string,live:bool}> */
    private function checks(): array
    {
        $out = [];
        foreach (app(AiPreflight::class)->run()['checks'] as $c) {
            $out[$c['id']] = $c;
        }

        return $out;
    }

    private function failingLive(): array
    {
        return array_values(array_map(fn ($c) => $c['id'], array_filter(app(AiPreflight::class)->run()['checks'], fn ($c) => $c['status'] === 'fail' && $c['live'])));
    }

    public function test_with_everything_in_place_the_integrity_checks_pass_and_say_independence_is_an_attestation(): void
    {
        $this->ready();

        $this->assertSame([], $this->failingLive());

        $checks = $this->checks();
        $this->assertSame('pass', $checks['integrity_anchor']['status']);
        $this->assertStringContainsString('ATTESTATION', $checks['integrity_anchor']['detail']);
        $this->assertSame('pass', $checks['integrity_consistency']['status']);
        $this->assertSame('pass', $checks['integrity_schema']['status']);
        $this->assertSame('pass', $checks['provider_spending_cap']['status']);
        $this->assertStringContainsString('not a verification', $checks['provider_spending_cap']['detail']);
        $this->assertSame('manual', $checks['manual_cache_controls']['status']);
    }

    public function test_with_no_anchor_live_readiness_is_blocked_and_says_why(): void
    {
        $this->ready();
        $this->removeAnchorDir();
        config(['ai.integrity.anchor.driver' => 'none']);
        $this->app->offsetUnset(IntegrityAnchor::class);

        $report = app(AiPreflight::class)->run();

        $this->assertFalse($report['live_ready']);
        $this->assertContains('integrity_anchor', $this->failingLive());
        $this->assertStringContainsString('database restore can silently erase spent budget', $this->checks()['integrity_anchor']['detail']);
    }

    public function test_each_integrity_condition_alone_blocks_live_readiness(): void
    {
        foreach ([
            'driver none' => ['ai.integrity.anchor.driver' => 'none'],
            'not attested' => ['ai.integrity.anchor.independence_attested' => false],
            'no reference' => ['ai.integrity.anchor.independence_reference' => ''],
            'short key' => ['ai.integrity.anchor.key' => 'short'],
            'relative path' => ['ai.integrity.anchor.path' => 'relative/dir'],
            'directory inside the application' => ['ai.integrity.anchor.path' => base_path()],
            'no spending cap' => ['ai.operations.provider_spending_cap_confirmed' => false],
            'cap without a reference' => ['ai.operations.provider_spending_cap_reference' => ''],
        ] as $label => $change) {
            $this->ready();
            config($change);
            $this->app->offsetUnset(IntegrityAnchor::class);

            $report = app(AiPreflight::class)->run();

            $this->assertFalse($report['live_ready'], $label);
            $this->assertNotSame([], $this->failingLive(), $label);
        }
    }

    public function test_a_database_that_disagrees_with_the_anchor_blocks_live_readiness(): void
    {
        $this->ready();
        $this->engine(); // nothing to spend here: reserve directly through the guard
        app(\App\Services\Ai\Usage\BudgetGuard::class)->reserve(\App\Services\Ai\Data\AiSurface::Vendor, 100, true, 100);

        DB::table(UsageMeter::LEDGER_TABLE)->delete();
        DB::table(UsageMeter::COUNTERS_TABLE)->delete();

        $this->assertContains('integrity_consistency', $this->failingLive());
        $this->assertStringContainsString('behind the anchor', $this->checks()['integrity_consistency']['detail']);
    }

    public function test_a_pending_reconciliation_and_a_missing_anchor_file_block_live_readiness(): void
    {
        $this->ready();
        $this->anchor()->setReconciliation('required', 'test');
        $this->assertContains('integrity_anchor', $this->failingLive());

        $this->anchor()->setReconciliation('ok', 'test');
        $this->assertNotContains('integrity_anchor', $this->failingLive());

        unlink($this->anchorDir.DIRECTORY_SEPARATOR.'ai-anchor.json');
        $this->assertContains('integrity_anchor', $this->failingLive());
    }

    public function test_the_missing_schema_is_reported(): void
    {
        $this->ready();
        $this->integrityMigration()->down();

        $this->assertSame('fail', $this->checks()['integrity_schema']['status']);

        $this->integrityMigration()->up();
    }

    public function test_the_preflight_stays_read_only_with_the_new_checks_and_prints_no_secret(): void
    {
        $this->ready();
        $before = [
            DB::table(UsageMeter::LEDGER_TABLE)->get()->all(),
            DB::table(UsageMeter::COUNTERS_TABLE)->get()->all(),
            (string) file_get_contents($this->anchorDir.DIRECTORY_SEPARATOR.'ai-anchor.json'),
        ];

        Artisan::call('ai:preflight', ['--live' => true, '--json' => true]);
        $out = Artisan::output();

        $this->assertSame($before, [
            DB::table(UsageMeter::LEDGER_TABLE)->get()->all(),
            DB::table(UsageMeter::COUNTERS_TABLE)->get()->all(),
            (string) file_get_contents($this->anchorDir.DIRECTORY_SEPARATOR.'ai-anchor.json'),
        ]);

        $this->assertStringNotContainsString(self::ANCHOR_KEY, $out);
        $this->assertStringNotContainsString($this->anchorDir, $out, 'the anchor location is not printed');
    }

    public function test_no_integrity_failure_is_hidden_when_the_provider_is_the_mock(): void
    {
        config(['ai.provider' => 'mock']);

        $report = app(AiPreflight::class)->run();

        $this->assertFalse($report['live_ready']);
        $this->assertSame('mock', $report['state']);
    }
}
