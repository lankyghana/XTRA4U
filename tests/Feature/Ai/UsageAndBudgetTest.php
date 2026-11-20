<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Data\AiUsage;
use App\Services\Ai\Exceptions\AiBudgetExceededException;
use App\Services\Ai\Exceptions\AiConfigurationException;
use App\Services\Ai\Exceptions\AiProviderException;

class UsageAndBudgetTest extends AiTestCase
{
    public function test_usage_is_counted_from_the_providers_reported_tokens_not_the_estimate(): void
    {
        $this->mockProvider()->respondWith('ok', new AiUsage(40, 10));

        $this->engine()->run($this->req('Hello'));

        $u = $this->usage();
        $this->assertSame(1, $u['global']['requests']);
        $this->assertSame(50, $u['global']['tokens'], 'the pessimistic reservation is replaced by the reported usage');
        $this->assertSame(1, $u['global']['successes']);
        $this->assertSame(0, $u['global']['failures']);
        $this->assertSame(1, $u['surface.vendor']['requests']);
        $this->assertSame(50, $u['surface.vendor']['tokens']);
        $this->assertSame(0, $u['surface.customer']['requests']);
    }

    public function test_when_the_provider_reports_no_usage_the_reservation_stands(): void
    {
        $this->mockProvider()->respondWith('ok', new AiUsage(null, null));

        $this->engine()->run($this->req('Hello'));

        $this->assertGreaterThan(500, $this->usage()['global']['tokens'], 'unknown usage is never treated as zero');
    }

    public function test_cost_is_computed_only_from_configured_pricing(): void
    {
        $this->mockProvider()->respondWith('ok', new AiUsage(1000, 500));
        $this->assertNull($this->engine()->run($this->req())->estimatedCostMicroUsd);
        $this->assertSame(0, $this->usage()['global']['cost_micro_usd']);

        config(['ai.pricing.mock.mock-model' => ['input_usd_per_million' => 2.5, 'output_usd_per_million' => 10, 'as_of' => '2026-01-01']]);
        $this->mockProvider()->respondWith('ok', new AiUsage(1000, 500));

        $result = $this->engine()->run($this->req());

        $this->assertSame(7500, $result->estimatedCostMicroUsd);
        $this->assertSame(7500, $this->usage()['global']['cost_micro_usd']);
        $this->assertSame(7500, $this->usage()['surface.vendor']['cost_micro_usd']);
    }

    public function test_incomplete_pricing_never_produces_a_cost(): void
    {
        config(['ai.pricing.mock.mock-model' => ['input_usd_per_million' => 2.5]]);
        $this->mockProvider()->respondWith('ok', new AiUsage(1000, 500));

        $this->assertNull($this->engine()->run($this->req())->estimatedCostMicroUsd);
    }

    public function test_the_global_request_limit_is_a_hard_stop_before_the_provider(): void
    {
        config(['ai.limits.global.daily_requests' => 1]);

        $this->engine()->run($this->req());

        try {
            $this->engine()->run($this->req('again', principal: 'vendor:2'));
            $this->fail('Expected the global budget to be exhausted.');
        } catch (AiBudgetExceededException $e) {
            $this->assertSame('ai_budget_exhausted', $e->errorCode());
        }

        $this->assertSame(1, $this->mockProvider()->callCount(), 'the provider was not called for the refused request');
        $this->assertSame(1, $this->usage()['global']['requests'], 'a refused request leaves no residue in the counters');
    }

    public function test_the_token_budget_is_reserved_before_the_call_and_rolled_back_on_refusal(): void
    {
        config(['ai.limits.global.daily_tokens' => 5]);

        try {
            $this->engine()->run($this->req());
            $this->fail('Expected exhaustion: the pessimistic estimate alone exceeds 5 tokens.');
        } catch (AiBudgetExceededException) {
        }

        $this->assertSame(0, $this->mockProvider()->callCount());
        $this->assertSame(0, $this->usage()['global']['tokens']);
        $this->assertSame(0, $this->usage()['global']['requests']);
        $this->assertSame(0, $this->usage()['surface.vendor']['requests'], 'partial reservations are rolled back in every scope');
    }

    public function test_per_surface_limits_are_independent(): void
    {
        config(['ai.limits.surfaces.customer.daily_requests' => 1]);

        $this->engine()->run($this->req('hi', AiSurface::Customer, 'guest:a'));

        try {
            $this->engine()->run($this->req('hi', AiSurface::Customer, 'guest:b'));
            $this->fail('Expected the customer surface budget to be exhausted.');
        } catch (AiBudgetExceededException) {
        }

        // The vendor surface is unaffected by the customer surface's cap.
        $this->engine()->run($this->req('hi', AiSurface::Vendor, 'vendor:1'));

        $u = $this->usage();
        $this->assertSame(1, $u['surface.customer']['requests']);
        $this->assertSame(1, $u['surface.vendor']['requests']);
        $this->assertSame(2, $u['global']['requests']);
    }

    public function test_a_zero_limit_blocks_everything(): void
    {
        config(['ai.limits.surfaces.developer.daily_requests' => 0]);

        $this->expectException(AiBudgetExceededException::class);
        try {
            $this->engine()->run($this->req('hi', AiSurface::Developer, 'vendor:9'));
        } finally {
            $this->assertSame(0, $this->mockProvider()->callCount());
        }
    }

    public function test_the_cost_budget_stops_further_requests_once_reached(): void
    {
        config([
            'ai.pricing.mock.mock-model' => ['input_usd_per_million' => 2.5, 'output_usd_per_million' => 10],
            'ai.limits.global.daily_cost_micro_usd' => 7500,
        ]);
        $this->mockProvider()->respondWith('ok', new AiUsage(1000, 500));

        $this->engine()->run($this->req());

        $this->expectException(AiBudgetExceededException::class);
        try {
            $this->engine()->run($this->req());
        } finally {
            $this->assertSame(1, $this->mockProvider()->callCount());
        }
    }

    public function test_a_failed_call_returns_its_token_reservation_but_still_counts_as_a_request(): void
    {
        $this->mockProvider()->failWith(new AiProviderException(AiProviderException::BAD_REQUEST));

        try {
            $this->engine()->run($this->req());
            $this->fail('Expected a provider failure.');
        } catch (AiProviderException) {
        }

        $u = $this->usage();
        $this->assertSame(1, $u['global']['requests'], 'attempts count, so a failing provider cannot be hammered past the request cap');
        $this->assertSame(0, $u['global']['tokens']);
        $this->assertSame(1, $u['global']['failures']);
        $this->assertSame(0, $u['global']['successes']);
    }

    public function test_the_offline_mock_may_run_without_budgets_but_is_still_counted(): void
    {
        $this->assertNull(config('ai.limits.global.daily_tokens'));

        $this->engine()->run($this->req());

        $this->assertSame(1, $this->usage()['global']['requests']);
    }

    public function test_a_live_provider_will_not_run_without_a_configured_global_token_budget(): void
    {
        $live = $this->useLiveProvider();

        try {
            $this->engine()->run($this->req());
            $this->fail('Expected refusal: "not configured" must not mean unlimited for a live provider.');
        } catch (AiConfigurationException $e) {
            $this->assertSame('ai_misconfigured', $e->errorCode());
        }

        $this->assertSame(0, $live->calls);
        $this->assertSame(0, $this->usage()['global']['requests']);
    }

    public function test_a_live_provider_also_needs_the_surface_budget(): void
    {
        $live = $this->useLiveProvider();
        config(['ai.limits.global.daily_tokens' => 1_000_000]); // global only: the vendor surface is still unconfigured

        $this->expectException(AiConfigurationException::class);
        try {
            $this->engine()->run($this->req());
        } finally {
            $this->assertSame(0, $live->calls);
            $this->assertSame(0, $this->usage()['global']['tokens'], 'nothing is reserved when configuration is missing');
        }
    }

    public function test_a_live_provider_runs_once_every_required_budget_is_configured(): void
    {
        $live = $this->useLiveProvider();
        config(['ai.limits.global.daily_tokens' => 1_000_000, 'ai.limits.surfaces.vendor.daily_tokens' => 500_000]);

        $result = $this->engine()->run($this->req());

        $this->assertSame('live ok', $result->response->text);
        $this->assertSame(1, $live->calls);
        $this->assertSame(150, $this->usage()['global']['tokens']);
    }

    public function test_a_live_provider_requires_an_explicit_model(): void
    {
        $live = $this->useLiveProvider();
        config(['ai.default_model' => null, 'ai.limits.global.daily_tokens' => 1_000_000, 'ai.limits.surfaces.vendor.daily_tokens' => 1_000_000]);

        $this->expectException(AiConfigurationException::class);
        try {
            $this->engine()->run($this->req());
        } finally {
            $this->assertSame(0, $live->calls, 'no placeholder model is ever invented for a live provider');
        }
    }

    public function test_garbage_budget_values_are_a_configuration_error_not_unlimited(): void
    {
        foreach (['abc', -5, 1.5] as $bad) {
            config(['ai.limits.global.daily_tokens' => $bad]);

            try {
                $this->engine()->run($this->req());
                $this->fail('Expected a configuration error for '.var_export($bad, true));
            } catch (AiConfigurationException) {
            }
        }

        $this->assertSame(0, $this->mockProvider()->callCount());
    }
}
