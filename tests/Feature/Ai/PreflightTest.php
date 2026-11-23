<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Preflight\AiPreflight;
use App\Services\Ai\Safety\KillSwitch;
use App\Services\Ai\Usage\BudgetGuard;
use App\Services\Ai\Usage\UsageMeter;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Ai\Support\ConfiguresOpenAi;

/**
 * `php artisan ai:preflight`: read-only, no network, never claims production readiness, never prints a secret.
 */
class PreflightTest extends AiTestCase
{
    use ConfiguresOpenAi;

    /** A configuration in which every AUTOMATIC live gate passes (fixtures only; real values are the operator's). */
    private function ready(): void
    {
        $this->configureOpenAi();
        config([
            'trustedproxy.proxies' => ['10.1.0.0/16'],
            'trustedproxy.rejected' => 0,
            'ai.operations.direct_connections_confirmed' => false,
            'ai.operations.customer_worker_capacity_confirmed' => true,
            'ai.operations.provider_spending_cap_confirmed' => true,
            'ai.operations.provider_spending_cap_reference' => 'CHG-2026-0042',
            'ai.rate_limits.customer.per_minute' => 5,
            'ai.rate_limits.vendor.per_minute' => 5,
            'ai.rate_limits.developer.per_minute' => 5,
        ]);
    }

    /** @return array<string,mixed> */
    private function report(): array
    {
        return app(AiPreflight::class)->run();
    }

    /** @return array<string,array{status:string,detail:string}> */
    private function byId(array $report): array
    {
        $out = [];
        foreach ($report['checks'] as $c) {
            $out[$c['id']] = $c;
        }

        return $out;
    }

    private function failing(array $report): array
    {
        return array_values(array_map(fn ($c) => $c['id'], array_filter($report['checks'], fn ($c) => $c['status'] === AiPreflight::FAIL && $c['live'])));
    }

    // ------------------------------------------------------------------------------------------ states

    public function test_everything_off_is_reported_as_safe_disabled(): void
    {
        config(['ai.enabled' => false, 'ai.provider' => 'mock']);

        $report = $this->report();

        $this->assertSame('disabled', $report['state']);
        $this->assertStringStartsWith('SAFE: AI is disabled', $report['verdict']);
        $this->assertFalse($report['live_ready']);
    }

    public function test_the_kill_switch_and_missing_surfaces_each_read_as_disabled(): void
    {
        app(KillSwitch::class)->engage();
        $this->assertSame('disabled', $this->report()['state']);
        app(KillSwitch::class)->release();

        foreach (['customer', 'vendor', 'developer'] as $s) {
            config(["ai.surfaces.{$s}.enabled" => false]);
        }
        $this->assertSame('disabled', $this->report()['state']);
    }

    public function test_the_mock_is_reported_as_mock_operation_with_no_external_call_possible(): void
    {
        config(['ai.enabled' => true, 'ai.provider' => 'mock']);

        $report = $this->report();

        $this->assertSame('mock', $report['state']);
        $this->assertStringContainsString('CONFIGURED FOR MOCK OPERATION', $report['verdict']);
        $this->assertStringContainsString('No external call is possible', $report['verdict']);
        $this->assertFalse($report['live_ready']);
    }

    public function test_a_selected_live_provider_with_missing_gates_is_blocked_not_ready(): void
    {
        config(['ai.enabled' => true, 'ai.provider' => 'openai', 'ai.default_model' => null, 'ai.providers.openai.api_key' => null]);

        $report = $this->report();

        $this->assertSame('live', $report['state']);
        $this->assertStringContainsString('LIVE PROVIDER SELECTED BUT BLOCKED', $report['verdict']);
        $this->assertFalse($report['live_ready']);
        $this->assertContains('api_key', $this->failing($report));
    }

    public function test_an_unknown_provider_is_misconfigured(): void
    {
        config(['ai.enabled' => true, 'ai.provider' => 'someone-else']);

        $this->assertSame('misconfigured', $this->report()['state']);
        $this->assertStringContainsString('MISCONFIGURED', $this->report()['verdict']);
    }

    public function test_a_fully_configured_setup_passes_every_automatic_gate_and_still_never_says_production_ready(): void
    {
        $this->ready();

        $report = $this->report();

        $this->assertSame([], $this->failing($report), 'no live gate fails: '.json_encode($this->failing($report)));
        $this->assertTrue($report['live_ready']);
        $this->assertSame('live', $report['state']);
        $this->assertStringContainsString('NOT production readiness', $report['verdict']);
        $this->assertGreaterThan(0, $report['manual'], 'manual items always remain');

        $text = json_encode($report);
        $this->assertDoesNotMatchRegularExpression('/production[ -]ready(?!ness)/i', $text);
        $this->assertDoesNotMatchRegularExpression('/\b(approved for launch|safe to launch|go live now)\b/i', $text);
    }

    // ------------------------------------------------------------------------------- each gate, alone

    /** @return array<string,array{0:array<string,mixed>,1:string}> */
    public static function breakers(): array
    {
        return [
            'provider is not openai' => [['ai.provider' => 'mock'], 'provider_is_openai'],
            'no api key' => [['ai.providers.openai.api_key' => null], 'api_key'],
            'malformed api key' => [['ai.providers.openai.api_key' => 'short'], 'api_key'],
            'privacy gate closed' => [['ai.external_processing.approved' => false], 'external_processing'],
            'privacy record incomplete' => [['ai.external_processing.approved_by' => null], 'external_processing'],
            'no model' => [['ai.default_model' => null], 'model'],
            'no pricing' => [['ai.pricing.openai' => []], 'pricing'],
            'global cost limit missing' => [['ai.limits.global.daily_cost_micro_usd' => null], 'budgets'],
            'global request limit is not an integer' => [['ai.limits.global.daily_requests' => 'many'], 'budgets'],
            'customer token limit missing' => [['ai.limits.surfaces.customer.daily_tokens' => null], 'budgets'],
            'vendor cost limit missing' => [['ai.limits.surfaces.vendor.daily_cost_micro_usd' => null], 'budgets'],
            'limit too small for one request' => [['ai.limits.global.daily_tokens' => 10], 'budget_fits_a_request'],
            'cost limit too small for one request' => [['ai.limits.surfaces.customer.daily_cost_micro_usd' => 1], 'budget_fits_a_request'],
            'output cap below provider minimum' => [['ai.request.max_output_tokens' => 8], 'request_limits'],
            'request limit missing' => [['ai.request.max_request_bytes' => null], 'request_limits'],
            'timeouts do not nest' => [['ai.request.turn_deadline_seconds' => 5], 'timeouts'],
            'connect timeout exceeds attempt' => [['ai.request.connect_timeout_seconds' => 99], 'timeouts'],
            'rate limit missing' => [['ai.rate_limits.customer.per_minute' => null], 'rate_limits'],
            'concurrency missing' => [['ai.concurrency.max_in_flight' => 0], 'concurrency'],
            'breaker invalid' => [['ai.circuit_breaker.failure_threshold' => 0], 'circuit_breaker'],
            'invalid trusted proxy entries' => [['trustedproxy.rejected' => 2], 'trusted_proxies_rejected'],
            'proxy topology undecided' => [['trustedproxy.proxies' => [], 'ai.operations.direct_connections_confirmed' => false], 'proxy_topology'],
            'worker capacity unconfirmed' => [['ai.operations.customer_worker_capacity_confirmed' => false], 'customer_worker_capacity'],
            'no surface selected' => [['ai.surfaces.customer.enabled' => false, 'ai.surfaces.vendor.enabled' => false, 'ai.surfaces.developer.enabled' => false], 'surfaces_selected'],
        ];
    }

    /** @param  array<string,mixed>  $change */
    #[DataProvider('breakers')]
    public function test_each_missing_gate_alone_blocks_live_readiness_and_is_named(array $change, string $expected): void
    {
        $this->ready();
        config($change);

        $report = $this->report();

        $this->assertFalse($report['live_ready'], json_encode($change));
        $this->assertContains($expected, $this->failing($report), 'the failing check is named');
        $this->assertSame('FAIL', strtoupper($this->byId($report)[$expected]['status']));
    }

    public function test_an_unsuitable_cache_blocks_live_readiness(): void
    {
        $this->ready();
        $this->fakeCacheEngine(false);

        $this->assertFalse($this->report()['live_ready']);
        $this->assertContains('cache_driver', $this->failing($this->report()));
    }

    public function test_the_budget_integrity_flag_blocks_live_readiness(): void
    {
        $this->ready();
        $meter = app(UsageMeter::class);
        $meter->flagIntegrityViolation($meter->today());

        $this->assertContains('budget_integrity', $this->failing($this->report()));
        $this->assertFalse($this->report()['live_ready']);
    }

    public function test_a_consistent_durable_store_passes_and_survives_a_cache_clear(): void
    {
        $this->ready();

        $check = $this->byId($this->report())['durable_budget_store'];
        $this->assertSame('PASS', strtoupper($check['status']));

        Cache::flush();
        $this->assertSame('PASS', strtoupper($this->byId($this->report())['durable_budget_store']['status']));
    }

    public function test_a_missing_durable_table_blocks_live_readiness(): void
    {
        $this->ready();
        Schema::drop('ai_budget_reservations');

        $report = $this->report();
        $this->assertContains('durable_budget_store', $this->failing($report));
        $this->assertFalse($report['live_ready']);
        $this->assertStringContainsString('ai_budget_reservations', $this->byId($report)['durable_budget_store']['detail']);
    }

    public function test_counters_that_disagree_with_the_ledger_block_live_readiness(): void
    {
        $this->ready();
        $reservation = app(BudgetGuard::class)->reserve(AiSurface::Vendor, 1000, true, 5000);
        app(BudgetGuard::class)->settle($reservation, 10, 5);
        $this->assertNotContains('durable_budget_store', $this->failing($this->report()));

        DB::table('ai_usage_counters')->where('scope', 'global')->update(['tokens' => 0]); // damage

        $report = $this->report();
        $this->assertContains('durable_budget_store', $this->failing($report));
        $this->assertFalse($report['live_ready']);
    }

    public function test_an_old_open_reservation_is_a_warning_not_a_failure(): void
    {
        $this->ready();
        $reservation = app(BudgetGuard::class)->reserve(AiSurface::Vendor, 1000, true, 5000);
        $this->assertArrayNotHasKey('budget_stale_reservations', $this->byId($this->report()));

        DB::table('ai_budget_reservations')->where('id', $reservation->id)->update(['created_at' => now()->subHour()]);

        $checks = $this->byId($this->report());
        $this->assertSame('WARN', strtoupper($checks['budget_stale_reservations']['status']));
        $this->assertNotContains('budget_stale_reservations', $this->failing($this->report()));
    }

    public function test_the_kill_switch_line_reflects_the_durable_state_without_writing_it(): void
    {
        $this->ready();
        app(KillSwitch::class)->engage();
        $before = DB::table('ai_runtime_flags')->get()->all();

        $checks = $this->byId($this->report());
        $this->assertStringContainsString('ENGAGED', $checks['kill_switch']['detail']);
        $this->assertEquals($before, DB::table('ai_runtime_flags')->get()->all(), 'preflight writes nothing');

        DB::table('ai_runtime_flags')->where('name', 'kill_switch')->delete(); // unreadable/missing state
        $this->assertStringContainsString('ENGAGED', $this->byId($this->report())['kill_switch']['detail']);
        $this->assertSame(0, DB::table('ai_runtime_flags')->where('name', 'kill_switch')->count(), 'and it did not recreate the row');
    }

    public function test_direct_connection_attestation_decides_the_address_source_when_no_proxy_is_listed(): void
    {
        $this->ready();
        config(['trustedproxy.proxies' => [], 'ai.operations.direct_connections_confirmed' => true]);

        $this->assertSame([], $this->failing($this->report()));
    }

    public function test_a_disabled_customer_surface_does_not_demand_proxy_or_worker_attestations(): void
    {
        $this->ready();
        config(['ai.surfaces.customer.enabled' => false, 'trustedproxy.proxies' => [], 'ai.operations.customer_worker_capacity_confirmed' => false]);

        $report = $this->report();

        $this->assertSame([], $this->failing($report));
        $this->assertSame(AiPreflight::WARN, $this->byId($report)['customer_worker_capacity']['status']);
    }

    // --------------------------------------------------------------------------- safety of the report

    public function test_no_secret_approval_detail_or_proxy_address_appears_in_the_output(): void
    {
        $this->ready();
        config(['ai.external_processing.approval_reference' => 'PRIVREF-SENTINEL-77', 'ai.external_processing.approved_by' => 'APPROVER-SENTINEL-77', 'trustedproxy.proxies' => ['10.77.0.0/16']]);

        $json = json_encode($this->report());
        $this->artisan('ai:preflight', ['--live' => true])->run();

        foreach ([self::FAKE_KEY, 'sk-test', 'PRIVREF-SENTINEL-77', 'APPROVER-SENTINEL-77', '10.77.0.0'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_the_command_prints_no_secret_in_text_or_json_mode(): void
    {
        $this->ready();
        config(['ai.external_processing.approval_reference' => 'PRIVREF-SENTINEL-77', 'trustedproxy.proxies' => ['10.77.0.0/16']]);

        foreach ([[], ['--json' => true], ['--live' => true], ['--live' => true, '--json' => true]] as $options) {
            Artisan::call('ai:preflight', $options);
            $out = Artisan::output();

            foreach ([self::FAKE_KEY, 'PRIVREF-SENTINEL-77', '10.77.0.0', 'Bearer'] as $secret) {
                $this->assertStringNotContainsString($secret, $out, json_encode($options));
            }
        }
    }

    public function test_the_command_exit_codes(): void
    {
        // Disabled and mock: standard mode is fine, --live is not.
        config(['ai.enabled' => false]);
        $this->assertSame(0, Artisan::call('ai:preflight'));
        $this->assertSame(1, Artisan::call('ai:preflight', ['--live' => true]));

        config(['ai.enabled' => true, 'ai.provider' => 'mock']);
        $this->assertSame(0, Artisan::call('ai:preflight'));
        $this->assertSame(1, Artisan::call('ai:preflight', ['--live' => true]));

        // An unrecognised provider on an enabled engine is a configuration fault even in standard mode.
        config(['ai.provider' => 'someone-else']);
        $this->assertSame(1, Artisan::call('ai:preflight'));

        // Everything satisfied: --live passes (and still prints the not-production-readiness caveat).
        $this->ready();
        $this->assertSame(0, Artisan::call('ai:preflight', ['--live' => true]));
        $this->assertStringContainsString('A pass never enables AI', Artisan::output());
    }

    public function test_json_output_is_valid_and_states_the_mode(): void
    {
        $this->ready();

        Artisan::call('ai:preflight', ['--json' => true, '--live' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertIsArray($decoded);
        $this->assertSame('live', $decoded['mode']);
        $this->assertTrue($decoded['live_ready']);
        $this->assertSame(['state', 'verdict', 'live_ready', 'failures', 'warnings', 'manual', 'checks', 'mode'], array_keys($decoded));
    }

    public function test_the_run_is_read_only_and_makes_no_network_request(): void
    {
        $this->ready();
        Http::swap(new Factory);
        Http::fake();

        $usageBefore = $this->usage();
        $configBefore = config()->all();
        $tablesBefore = $this->rowCounts();
        $killBefore = app(KillSwitch::class)->engaged();

        Artisan::call('ai:preflight', ['--live' => true]);
        Artisan::call('ai:preflight', ['--json' => true]);

        Http::assertNothingSent();
        $this->assertSame($usageBefore, $this->usage(), 'no counter was created or changed');
        $this->assertSame($configBefore, config()->all(), 'no configuration was changed');
        $this->assertSame($tablesBefore, $this->rowCounts(), 'no business data was touched');
        $this->assertSame($killBefore, app(KillSwitch::class)->engaged());
    }

    public function test_the_source_has_no_network_client_and_no_writes(): void
    {
        foreach (['Services/Ai/Preflight/AiPreflight.php', 'Console/Commands/AiPreflight.php'] as $file) {
            $src = (string) file_get_contents(app_path($file));

            $this->assertDoesNotMatchRegularExpression('/\bHttp::|Illuminate\\\\Http\\\\Client|curl_|file_get_contents\(\s*[\'"]http|fsockopen|stream_socket_client/', $src, $file);
            $this->assertDoesNotMatchRegularExpression('/Cache::(put|forever|add|increment|decrement|forget|flush|lock)|->(put|forever|increment|decrement|forget|flush)\(|DB::(insert|update|delete|statement|unprepared)|Schema::(?!hasTable\()|->save\(|->delete\(|->create\(|->update\(|Artisan::call|config\(\s*\[/', $src, $file);
        }
    }

    public function test_every_failing_check_explains_itself_and_every_status_is_known(): void
    {
        $report = $this->report();

        foreach ($report['checks'] as $check) {
            $this->assertContains($check['status'], [AiPreflight::PASS, AiPreflight::WARN, AiPreflight::FAIL, AiPreflight::MANUAL, AiPreflight::INFO]);
            $this->assertNotSame('', trim($check['detail']), $check['id']);
            $this->assertNotSame('', trim($check['title']), $check['id']);
        }
        $this->assertSame(count(array_filter($report['checks'], fn ($c) => $c['status'] === AiPreflight::FAIL)), $report['failures']);
    }

    /** @return array<string,int> */
    private function rowCounts(): array
    {
        $counts = [];
        foreach (['orders', 'transactions', 'vendors', 'products', 'settings'] as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = DB::table($table)->count();
            }
        }

        return $counts;
    }
}
