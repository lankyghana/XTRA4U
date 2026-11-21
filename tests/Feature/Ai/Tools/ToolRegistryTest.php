<?php

namespace Tests\Feature\Ai\Tools;

use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Tools\Exceptions\AiToolRegistrationException;
use App\Services\Ai\Tools\Schema\ToolParameter;
use App\Services\Ai\Tools\Schema\ToolSchema;
use App\Services\Ai\Tools\ToolRegistry;
use Tests\Feature\Ai\Tools\Support\FakeTool;

class ToolRegistryTest extends ToolTestCase
{
    public function test_a_tool_can_be_registered_and_looked_up_by_name(): void
    {
        $tool = new FakeTool('test_lookup');
        $this->registry->register($tool);

        $this->assertTrue($this->registry->has('test_lookup'));
        $this->assertSame($tool, $this->registry->get('test_lookup'));
        $this->assertSame(['test_lookup'], $this->registry->names());
    }

    public function test_a_duplicate_name_is_rejected_even_from_a_different_object(): void
    {
        $this->registry->register(new FakeTool('test_lookup'));

        $this->expectException(AiToolRegistrationException::class);
        $this->registry->register(new FakeTool('test_lookup', [AiSurface::Customer]));
    }

    public function test_an_unknown_tool_is_rejected(): void
    {
        $this->assertFalse($this->registry->has('nope_tool'));
        $this->assertNull($this->registry->resolve('nope_tool', AiSurface::Vendor));

        $this->expectException(AiToolRegistrationException::class);
        $this->registry->get('nope_tool');
    }

    public function test_discovery_returns_only_tools_for_the_requested_surface(): void
    {
        $vendorTool = $this->enable(new FakeTool('vendor_only', [AiSurface::Vendor]), AiSurface::Vendor);
        $customerTool = $this->enable(new FakeTool('customer_only', [AiSurface::Customer]), AiSurface::Customer);
        $shared = $this->enable(new FakeTool('shared_tool', [AiSurface::Vendor, AiSurface::Developer]), AiSurface::Vendor, AiSurface::Developer);

        $this->assertSame([$vendorTool, $shared], $this->registry->forSurface(AiSurface::Vendor));
        $this->assertSame([$customerTool], $this->registry->forSurface(AiSurface::Customer));
        $this->assertSame([$shared], $this->registry->forSurface(AiSurface::Developer));

        $names = array_column($this->registry->definitionsFor(AiSurface::Customer), 'name');
        $this->assertSame(['customer_only'], $names, 'tools of another surface are never exposed');
    }

    public function test_the_shipped_allowlist_denies_every_tool_that_is_not_listed(): void
    {
        // M5.3 shipped all three empty. M5.4 lists exactly the Developer Assistant's four tools on `developer` and M5.5
        // the Vendor Assistant's six on `vendor`; M5.6 lists the Customer Assistant's four on `customer`, and an unlisted tool is still denied everywhere.
        $this->assertSame(['customer' => \App\Services\Ai\CustomerAssistant\CustomerAssistant::TOOLS, 'vendor' => \App\Services\Ai\VendorAssistant\VendorAssistant::TOOLS], \Illuminate\Support\Arr::only(config('ai.tools.allowlist'), ['customer', 'vendor']));
        $this->assertSame(\App\Services\Ai\Developer\DeveloperAssistant::TOOLS, config('ai.tools.allowlist.developer'));

        $this->registry->register(new FakeTool('test_lookup', [AiSurface::Vendor, AiSurface::Customer, AiSurface::Developer]));

        foreach (AiSurface::cases() as $surface) {
            $this->assertSame([], $this->registry->forSurface($surface), "{$surface->value}: registered but not allow-listed");
            $this->assertSame([], $this->registry->definitionsFor($surface));
            $this->assertNull($this->registry->resolve('test_lookup', $surface));
        }
    }

    public function test_a_tool_must_be_both_declared_for_the_surface_and_allow_listed_on_it(): void
    {
        $this->registry->register(new FakeTool('test_lookup', [AiSurface::Vendor]));

        // allow-listed on the customer surface, but the tool was never designed for it
        config(['ai.tools.allowlist.customer' => ['test_lookup']]);
        $this->assertNull($this->registry->resolve('test_lookup', AiSurface::Customer));

        // declared for vendor, but not allow-listed there
        $this->assertNull($this->registry->resolve('test_lookup', AiSurface::Vendor));

        config(['ai.tools.allowlist.vendor' => ['test_lookup']]);
        $this->assertNotNull($this->registry->resolve('test_lookup', AiSurface::Vendor));
    }

    public function test_a_malformed_allowlist_fails_closed(): void
    {
        $this->registry->register(new FakeTool('test_lookup'));

        config(['ai.tools.allowlist.vendor' => 'test_lookup']);
        $this->assertNull($this->registry->resolve('test_lookup', AiSurface::Vendor));

        config(['ai.tools.allowlist.vendor' => null]);
        $this->assertNull($this->registry->resolve('test_lookup', AiSurface::Vendor));
    }

    public function test_a_sealed_registry_accepts_nothing_more(): void
    {
        $this->registry->register(new FakeTool('first_tool'));
        $this->registry->seal();

        $this->assertTrue($this->registry->isSealed());
        $this->expectException(AiToolRegistrationException::class);
        $this->registry->register(new FakeTool('second_tool'));
    }

    public function test_the_application_registry_is_sealed_after_boot_and_holds_only_the_developer_and_vendor_tools(): void
    {
        $app = $this->app->make(ToolRegistry::class);

        $this->assertTrue($app->isSealed());
        $this->assertEqualsCanonicalizing(
            array_merge(\App\Services\Ai\Developer\DeveloperAssistant::TOOLS, \App\Services\Ai\VendorAssistant\VendorAssistant::TOOLS, \App\Services\Ai\CustomerAssistant\CustomerAssistant::TOOLS),
            $app->names(),
            'M5.4 registers the four developer tools, M5.5 the six vendor tools and M5.6 the four customer tools, nothing else'
        );
        $this->assertSame($app, $this->app->make(ToolRegistry::class), 'one shared registry');

        $this->expectException(AiToolRegistrationException::class);
        $app->register(new FakeTool('late_tool'));
    }

    public function test_registration_validates_the_definition(): void
    {
        $bad = [
            'upper case' => fn () => new FakeTool('Bad_Name'),
            'dots' => fn () => new FakeTool('catalog.search'),
            'too short' => fn () => new FakeTool('ab'),
            'empty description' => fn () => new FakeTool('test_lookup', description: '  '),
            'long description' => fn () => new FakeTool('test_lookup', description: str_repeat('x', 301)),
            'no surfaces' => fn () => new FakeTool('test_lookup', []),
        ];

        foreach ($bad as $label => $make) {
            try {
                $this->registry->register($make());
                $this->fail("{$label}: expected a registration error");
            } catch (AiToolRegistrationException) {
                $this->assertSame([], $this->registry->names(), $label);
            }
        }
    }

    public function test_a_tool_cannot_declare_an_identity_or_permission_parameter(): void
    {
        foreach (['vendor_id', 'user_id', 'customerId', 'actor_id', 'target_vendor_id', 'role', 'is_admin', 'permissions', 'context'] as $name) {
            // Parameter names must also be lowercase snake_case, so camelCase is rejected one way or the other.
            try {
                $tool = new FakeTool('test_lookup', schema: new ToolSchema(ToolParameter::integer(strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name)), 'An id.', 1, 10)));
                $this->registry->register($tool);
                $this->fail("{$name}: a prohibited parameter must not be registrable");
            } catch (AiToolRegistrationException $e) {
                $this->assertStringContainsString('prohibited', $e->getMessage());
            }
        }
    }

    public function test_tools_cannot_be_loaded_from_strings(): void
    {
        $this->expectException(\TypeError::class);
        $this->registry->register('App\\Models\\Vendor');
    }

    public function test_definitions_are_provider_neutral_json_schema(): void
    {
        $this->enable(new FakeTool('test_lookup'), AiSurface::Vendor);

        $def = $this->registry->definitionsFor(AiSurface::Vendor)[0];

        $this->assertSame(['name', 'description', 'parameters'], array_keys($def));
        $schema = json_decode(json_encode($def['parameters']), true);
        $this->assertSame('object', $schema['type']);
        $this->assertFalse($schema['additionalProperties'], 'unknown properties are forbidden');
        $this->assertSame(['query'], $schema['required']);
        $this->assertSame(['query', 'limit', 'sort', 'verbose'], array_keys($schema['properties']));
        $this->assertArrayNotHasKey('vendor_id', $schema['properties']);
    }
}
