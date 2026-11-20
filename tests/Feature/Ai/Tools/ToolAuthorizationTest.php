<?php

namespace Tests\Feature\Ai\Tools;

use App\Services\Ai\Data\AiMessage;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Safety\KillSwitch;
use App\Services\Ai\Tools\ToolContext;
use Tests\Feature\Ai\Tools\Support\FakeTool;

class ToolAuthorizationTest extends ToolTestCase
{
    public function test_a_permitted_tool_executes(): void
    {
        $tool = $this->enable(new FakeTool, AiSurface::Vendor);

        $result = $this->callTool('test_lookup', ['query' => 'x'], $this->vendorContext());

        $this->assertTrue($result->isOk());
        $this->assertTrue($result->wasExecuted());
        $this->assertSame(1, $tool->handled);
    }

    public function test_a_tool_that_is_not_allowed_on_the_surface_is_rejected_like_an_unknown_tool(): void
    {
        $tool = $this->enable(new FakeTool('vendor_tool', [AiSurface::Vendor]), AiSurface::Vendor);

        $hallucinated = $this->callTool('vendor_sales_summary', ['query' => 'x'], $this->vendorContext());
        $wrongSurface = $this->callTool('vendor_tool', ['query' => 'x'], ToolContext::forGuest('server-session-aaaaaaaaaaaaaaaa'), 'c2');

        $this->assertSame('unknown_tool', $hallucinated->errorCode);
        $this->assertSame('unknown_tool', $wrongSurface->errorCode, 'same answer, so the model cannot probe which tools exist elsewhere');
        $this->assertFalse($wrongSurface->wasExecuted());
        $this->assertSame(0, $tool->handled);
    }

    public function test_a_registered_tool_that_is_not_allow_listed_is_not_executable(): void
    {
        $tool = new FakeTool('test_lookup', [AiSurface::Vendor]);
        $this->registry->register($tool); // registered, declared for vendor, but the shipped allowlist is empty

        $result = $this->callTool('test_lookup', ['query' => 'x'], $this->vendorContext());

        $this->assertSame('unknown_tool', $result->errorCode);
        $this->assertSame(0, $tool->handled);
    }

    public function test_appearing_in_a_model_request_does_not_make_a_tool_executable(): void
    {
        $tool = $this->enable(new FakeTool, AiSurface::Vendor);

        // The definition IS handed to the model...
        $request = new AiRequest(AiSurface::Vendor, 'vendor:1', [AiMessage::user('hi')], tools: $this->registry->definitionsFor(AiSurface::Vendor));
        $this->assertSame('test_lookup', $request->tools[0]['name']);

        // ...but access is revoked before the model answers: execution re-checks, it does not trust the listing.
        config(['ai.tools.allowlist.vendor' => []]);

        $result = $this->callTool('test_lookup', ['query' => 'x'], $this->vendorContext());
        $this->assertSame('unknown_tool', $result->errorCode);
        $this->assertSame(0, $tool->handled);
    }

    public function test_calling_the_executor_directly_cannot_bypass_surface_restrictions(): void
    {
        $customerTool = $this->enable(new FakeTool('customer_tool', [AiSurface::Customer]), AiSurface::Customer);
        $vendorTool = $this->enable(new FakeTool('vendor_tool', [AiSurface::Vendor]), AiSurface::Vendor);
        $executor = $this->executor();

        $vendorCtx = $this->vendorContext();
        $guestCtx = ToolContext::forGuest('server-session-aaaaaaaaaaaaaaaa');

        $this->assertSame('unknown_tool', $executor->execute($this->toolCall('customer_tool', ['query' => 'x']), $vendorCtx, $executor->beginTurn())->errorCode);
        $this->assertSame('unknown_tool', $executor->execute($this->toolCall('vendor_tool', ['query' => 'x']), $guestCtx, $executor->beginTurn())->errorCode);
        $this->assertSame('unknown_tool', $executor->execute($this->toolCall('vendor_tool', ['query' => 'x']), ToolContext::forDeveloper($this->approvedVendor()), $executor->beginTurn())->errorCode);
        $this->assertSame(0, $customerTool->handled + $vendorTool->handled);
    }

    public function test_authorization_runs_on_every_execution_not_once(): void
    {
        $tool = $this->enable(new FakeTool, AiSurface::Vendor);
        $ctx = $this->vendorContext();
        $executor = $this->executor();
        $turn = $executor->beginTurn();

        $executor->execute($this->toolCall('test_lookup', ['query' => 'a'], 'c1'), $ctx, $turn);
        $executor->execute($this->toolCall('test_lookup', ['query' => 'b'], 'c2'), $ctx, $turn);
        $executor->execute($this->toolCall('test_lookup', ['query' => 'c'], 'c3'), $ctx, $turn);

        $this->assertSame(3, $tool->authorized);
        $this->assertSame(3, $tool->handled);
    }

    public function test_a_tool_can_deny_and_a_denial_is_final(): void
    {
        $tool = $this->enable(new FakeTool, AiSurface::Vendor);
        $mine = $this->vendorContext();
        $tool->authorizer = fn (ToolContext $c) => $c->vendorId === $mine->vendorId;

        $this->assertTrue($this->callTool('test_lookup', ['query' => 'x'], $mine)->isOk());

        $r = $this->callTool('test_lookup', ['query' => 'x'], $this->vendorContext(), 'c2');
        $this->assertSame('not_authorized', $r->errorCode);
        $this->assertSame(1, $tool->handled);
        $this->assertSame(2, $tool->authorized);
    }

    public function test_an_authorizer_that_throws_or_does_not_return_true_denies(): void
    {
        $tool = $this->enable(new FakeTool, AiSurface::Vendor);
        $ctx = $this->vendorContext();

        foreach ([fn () => throw new \RuntimeException('boom'), fn () => null, fn () => false] as $i => $authorizer) {
            $tool->authorizer = $authorizer;
            $r = $this->callTool('test_lookup', ['query' => 'x'], $ctx, "c{$i}");
            $this->assertSame('not_authorized', $r->errorCode, "authorizer #{$i}");
        }

        $this->assertSame(0, $tool->handled);
    }

    public function test_the_runtime_kill_switch_and_surface_switch_still_apply_to_tools(): void
    {
        $tool = $this->enable(new FakeTool, AiSurface::Vendor);
        $ctx = $this->vendorContext();

        config(['ai.surfaces.vendor.enabled' => false]);
        $this->assertSame('tools_disabled', $this->callTool('test_lookup', ['query' => 'x'], $ctx)->errorCode);

        config(['ai.surfaces.vendor.enabled' => true, 'ai.enabled' => false]);
        $this->assertSame('tools_disabled', $this->callTool('test_lookup', ['query' => 'x'], $ctx, 'c2')->errorCode);

        config(['ai.enabled' => true]);
        app(KillSwitch::class)->engage();
        $this->assertSame('tools_disabled', $this->callTool('test_lookup', ['query' => 'x'], $ctx, 'c3')->errorCode);

        app(KillSwitch::class)->release();
        $this->assertTrue($this->callTool('test_lookup', ['query' => 'x'], $ctx, 'c4')->isOk());
        $this->assertSame(1, $tool->handled);
    }

    public function test_an_invalid_tool_configuration_fails_closed(): void
    {
        $tool = $this->enable(new FakeTool, AiSurface::Vendor);

        foreach (['max_calls_per_turn' => 0, 'max_result_bytes' => 'big', 'max_argument_bytes' => null] as $key => $bad) {
            config(["ai.tools.limits.{$key}" => $bad]);
            $r = $this->callTool('test_lookup', ['query' => 'x'], $this->vendorContext(), 'c_'.$key);
            $this->assertSame('rejected', $r->status->value, $key);
            config(["ai.tools.limits.{$key}" => 4]);
        }

        $this->assertSame(0, $tool->handled);
    }
}
