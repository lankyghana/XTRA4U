<?php

namespace Tests\Feature\Ai\Tools;

use App\Services\Ai\Data\AiMessage;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiResponse;
use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Data\AiToolCall;
use App\Services\Ai\Data\AiUsage;
use App\Services\Ai\Exceptions\AiDisabledException;
use App\Services\Ai\Tools\ToolContext;
use App\Services\Ai\Tools\ToolExecutor;
use Tests\Feature\Ai\Tools\Support\FakeTool;

/**
 * The tool system next to the M5.2 engine: definitions travel in AiRequest::$tools, the orchestrator only CARRIES the
 * model's tool calls back, and nothing runs until trusted code hands them to the executor.
 */
class ToolIntegrationTest extends ToolTestCase
{
    public function test_definitions_ride_in_the_request_and_the_orchestrator_does_not_execute_tool_calls(): void
    {
        $tool = $this->enable(new FakeTool, AiSurface::Vendor);
        $vendor = $this->approvedVendor();
        $context = ToolContext::forVendor($vendor);

        $this->mockProvider()->queue(new AiResponse(
            text: '',
            provider: 'mock',
            model: 'mock-model',
            usage: new AiUsage(20, 10),
            toolCalls: [new AiToolCall('call_a', 'test_lookup', ['query' => 'mtn']), new AiToolCall('call_b', 'test_lookup', ['query' => 'x', 'vendor_id' => 999])],
            finishReason: 'tool_calls',
        ));

        $request = new AiRequest(AiSurface::Vendor, $context->principalKey(), [AiMessage::user('find mtn')], tools: $this->registry->definitionsFor(AiSurface::Vendor));
        $result = $this->engine()->run($request);

        // the provider was offered exactly this surface's tool definitions
        $this->assertSame('test_lookup', $this->mockProvider()->calls()[0]->tools[0]['name']);
        $this->assertGreaterThan(0, strlen((string) json_encode($request->tools)), 'tool schemas count toward the request size/budget estimate');

        // M5.2 behaviour: the calls are carried, not run
        $this->assertCount(2, $result->response->toolCalls);
        $this->assertSame(0, $tool->handled);

        // trusted code decides to execute them, with the SERVER-built context
        $results = $this->executor()->executeTurn($result->response->toolCalls, $context);

        $this->assertTrue($results[0]->isOk());
        $this->assertSame($vendor->id, $results[0]->data['vendor_id']);
        $this->assertSame('prohibited_argument', $results[1]->errorCode, 'the model tried to pick another vendor');
        $this->assertSame(1, $tool->handled);
    }

    public function test_the_request_for_one_surface_never_carries_another_surfaces_tools(): void
    {
        $this->enable(new FakeTool('vendor_tool', [AiSurface::Vendor]), AiSurface::Vendor);
        $this->enable(new FakeTool('customer_tool', [AiSurface::Customer]), AiSurface::Customer);

        $this->assertSame(['vendor_tool'], array_column($this->registry->definitionsFor(AiSurface::Vendor), 'name'));
        $this->assertSame(['customer_tool'], array_column($this->registry->definitionsFor(AiSurface::Customer), 'name'));
        $this->assertSame([], $this->registry->definitionsFor(AiSurface::Developer));
    }

    public function test_the_engines_gates_still_apply_before_any_tool_can_be_requested(): void
    {
        $this->enable(new FakeTool, AiSurface::Vendor);
        config(['ai.enabled' => false]);

        $this->expectException(AiDisabledException::class);
        try {
            $this->engine()->run(new AiRequest(AiSurface::Vendor, 'vendor:1', [AiMessage::user('hi')], tools: $this->registry->definitionsFor(AiSurface::Vendor)));
        } finally {
            $this->assertSame(0, $this->mockProvider()->callCount());
        }
    }

    public function test_the_container_wires_a_sealed_empty_executor(): void
    {
        $executor = $this->app->make(ToolExecutor::class);
        $r = $executor->execute(new AiToolCall('c1', 'anything_at_all', []), $this->vendorContext(), $executor->beginTurn());

        $this->assertSame('unknown_tool', $r->errorCode);
    }

    public function test_tool_calls_are_the_existing_typed_structure(): void
    {
        $call = new AiToolCall('id_1', 'test_lookup', ['query' => 'x']);

        $this->assertSame(['id_1', 'test_lookup', ['query' => 'x']], [$call->id, $call->name, $call->arguments]);
    }
}
