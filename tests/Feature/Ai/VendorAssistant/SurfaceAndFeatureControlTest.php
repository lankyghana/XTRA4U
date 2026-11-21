<?php

namespace Tests\Feature\Ai\VendorAssistant;

use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Developer\DeveloperAssistant;
use App\Services\Ai\Tools\Exceptions\AiToolRegistrationException;
use App\Services\Ai\Tools\ToolContext;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\Ai\VendorAssistant\VendorAssistant;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\Ai\Tools\Support\FakeTool;

/**
 * The vendor surface and its fail-closed switches: ships OFF, respects the kill switch, the surface flag, the provider
 * configuration and the tool allowlist, and shares no tool with another surface.
 */
class SurfaceAndFeatureControlTest extends VendorAssistantTestCase
{
    /** One valid call per tool, for "does this exact tool run here?" loops. */
    private function sampleArguments(string $tool): array
    {
        return $tool === 'vendor_get_my_order' ? ['order_ref' => 1] : [];
    }

    public function test_the_shipped_defaults_keep_everything_off_and_mock_only(): void
    {
        $defaults = require config_path('ai.php');

        $this->assertFalse($defaults['enabled']);
        $this->assertFalse($defaults['surfaces']['vendor']['enabled'], 'the vendor surface is off by default');
        $this->assertSame('mock', $defaults['provider']);
        $this->assertNull($defaults['default_model']);
        $this->assertNull($defaults['providers']['openai']['api_key'] ?? null, 'no provider credential ships');
        $this->assertSame(VendorAssistant::TOOLS, $defaults['tools']['allowlist']['vendor']);
        $this->assertSame(\App\Services\Ai\CustomerAssistant\CustomerAssistant::TOOLS, $defaults['tools']['allowlist']['customer']);
    }

    public function test_vendor_tools_are_available_on_the_vendor_surface(): void
    {
        $registry = app(ToolRegistry::class);

        $this->assertTrue($registry->isSealed());
        $offered = array_map(fn ($d) => $d['name'], $registry->definitionsFor(AiSurface::Vendor));

        $this->assertEqualsCanonicalizing(VendorAssistant::TOOLS, $offered);
        $this->assertSame(VendorAssistant::TOOLS, config('ai.tools.allowlist.vendor'));

        foreach (VendorAssistant::TOOLS as $name) {
            $this->assertSame([AiSurface::Vendor], $registry->get($name)->surfaces(), "{$name} is vendor-only");
        }
    }

    public function test_the_developer_and_customer_surfaces_cannot_discover_or_execute_vendor_tools(): void
    {
        $registry = app(ToolRegistry::class);
        $developerOffered = array_map(fn ($d) => $d['name'], $registry->definitionsFor(AiSurface::Developer));
        $this->assertSame([], array_values(array_intersect($developerOffered, VendorAssistant::TOOLS)));
        // (M5.6: the customer surface offers its OWN four tools, but never a vendor one.)
        $customerOffered = array_map(fn ($d) => $d['name'], $registry->definitionsFor(AiSurface::Customer));
        $this->assertSame([], array_values(array_intersect($customerOffered, VendorAssistant::TOOLS)));

        // Even if someone mistakenly allow-lists the vendor tools on the other surfaces, the tools do not declare them.
        config(['ai.tools.allowlist.developer' => array_merge(DeveloperAssistant::TOOLS, VendorAssistant::TOOLS), 'ai.tools.allowlist.customer' => VendorAssistant::TOOLS]);
        $this->assertSame([], $registry->definitionsFor(AiSurface::Customer));
        $developerOffered = array_map(fn ($d) => $d['name'], $registry->definitionsFor(AiSurface::Developer));
        $this->assertSame([], array_values(array_intersect($developerOffered, VendorAssistant::TOOLS)));

        $vendor = $this->approvedVendor();
        foreach ([ToolContext::forDeveloper($vendor), ToolContext::forGuest(str_repeat('s', 40))] as $context) {
            foreach (VendorAssistant::TOOLS as $name) {
                $result = $this->runTool($name, $this->sampleArguments($name), $context);

                $this->assertSame('unknown_tool', $result->errorCode, "{$name} on {$context->surface->value}");
                $this->assertFalse($result->wasExecuted());
            }
        }
    }

    public function test_an_empty_vendor_allowlist_denies_every_tool_and_the_assistant_is_unavailable(): void
    {
        config(['ai.tools.allowlist.vendor' => []]);
        $vendor = $this->approvedVendor();

        foreach (VendorAssistant::TOOLS as $name) {
            $this->assertSame('unknown_tool', $this->runTool($name, $this->sampleArguments($name), ToolContext::forVendor($vendor))->errorCode, $name);
        }

        $this->assertFalse(app(VendorAssistant::class)->isAvailable());
        $this->signedIn($vendor)->get(route('vendor.assistant.show'))->assertOk()->assertSee('data-assistant-state="unavailable"', false);

        $this->ask($vendor, 'hello')->assertStatus(503);
        $this->assertSame(0, $this->mockProvider()->callCount(), 'a refused request never reaches the provider');
    }

    public function test_removing_one_tool_from_the_allowlist_prevents_that_tool_immediately(): void
    {
        $vendor = $this->approvedVendor();
        $context = ToolContext::forVendor($vendor);

        $this->assertTrue($this->runTool('vendor_get_my_store_info', [], $context)->isOk());

        config(['ai.tools.allowlist.vendor' => array_values(array_diff(VendorAssistant::TOOLS, ['vendor_get_my_store_info']))]);

        $this->assertSame('unknown_tool', $this->runTool('vendor_get_my_store_info', [], $context, 'call_2')->errorCode);
        $this->assertTrue($this->runTool('vendor_get_dashboard_summary', [], $context, 'call_3')->isOk(), 'the other tools are unaffected');
        $this->assertFalse(app(VendorAssistant::class)->isAvailable(), 'a missing tool makes the assistant refuse rather than guess');
    }

    public function test_the_global_kill_switch_and_flags_block_the_assistant_before_any_provider_call(): void
    {
        $vendor = $this->approvedVendor();
        $context = ToolContext::forVendor($vendor);

        foreach ([
            'engine off' => ['ai.enabled' => false],
            'surface off' => ['ai.surfaces.vendor.enabled' => false],
        ] as $label => $override) {
            config($override);

            $this->ask($vendor, 'hello')->assertStatus(503);
            $this->assertSame('tools_disabled', $this->runTool('vendor_get_dashboard_summary', [], $context)->errorCode, $label);
            $this->signedIn($vendor)->get(route('vendor.assistant.show'))->assertOk()->assertSee('data-assistant-state="unavailable"', false);

            config(['ai.enabled' => true, 'ai.surfaces.vendor.enabled' => true]);
            RateLimiter::clear('vendor-assistant:vendor:'.$vendor->id);
        }

        $this->assertSame(0, $this->mockProvider()->callCount());
    }

    public function test_the_runtime_kill_switch_command_blocks_the_assistant(): void
    {
        $vendor = $this->approvedVendor();

        $this->artisan('ai:kill-switch', ['action' => 'engage'])->run();
        try {
            $this->ask($vendor, 'hello')->assertStatus(503);
            $this->assertSame(0, $this->mockProvider()->callCount());
        } finally {
            $this->artisan('ai:kill-switch', ['action' => 'release'])->run();
        }
    }

    public function test_a_misconfigured_provider_makes_the_assistant_unavailable(): void
    {
        config(['ai.provider' => 'openai']); // not implemented: must fail closed and make no network request

        $vendor = $this->approvedVendor();
        $this->assertFalse(app(VendorAssistant::class)->isAvailable());
        $this->ask($vendor, 'hello')->assertStatus(503);
    }

    public function test_the_nav_link_follows_availability(): void
    {
        $vendor = $this->approvedVendor();
        $url = route('vendor.assistant.show');

        $this->signedIn($vendor)->get(route('vendor.dashboard'))->assertOk()->assertSee($url, false);

        config(['ai.surfaces.vendor.enabled' => false]);
        $this->signedIn($vendor)->get(route('vendor.dashboard'))->assertOk()->assertDontSee($url, false);
    }

    public function test_the_registry_stays_sealed(): void
    {
        $this->expectException(AiToolRegistrationException::class);
        app(ToolRegistry::class)->register(new FakeTool('late_tool', [AiSurface::Vendor]));
    }
}
