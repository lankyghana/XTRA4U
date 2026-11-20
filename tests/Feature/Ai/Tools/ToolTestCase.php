<?php

namespace Tests\Feature\Ai\Tools;

use App\Models\Vendor;
use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Data\AiToolCall;
use App\Services\Ai\Safety\KillSwitch;
use App\Services\Ai\Tools\ReadOnlyGuard;
use App\Services\Ai\Tools\ToolContext;
use App\Services\Ai\Tools\ToolContextVerifier;
use App\Services\Ai\Tools\ToolExecutor;
use App\Services\Ai\Tools\ToolLogger;
use App\Services\Ai\Tools\ToolRegistry;
use App\Services\Ai\Tools\ToolResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Ai\AiTestCase;
use Tests\Feature\Ai\Tools\Support\FakeTool;

/**
 * AI engine ON (offline mock), an empty allowlist (as shipped), a fresh unsealed registry per test, and a real
 * database for vendor-backed contexts. Http is faked and asserted empty in tearDown (inherited): tools make no requests.
 */
abstract class ToolTestCase extends AiTestCase
{
    use RefreshDatabase;

    protected ToolRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new ToolRegistry;
    }

    protected function executor(?ToolRegistry $registry = null): ToolExecutor
    {
        return new ToolExecutor($registry ?? $this->registry, new ToolContextVerifier, new ReadOnlyGuard, app(KillSwitch::class), new ToolLogger);
    }

    /** Register the tool AND list it on the given surfaces' allowlists (both are needed for availability). */
    protected function enable(FakeTool $tool, AiSurface ...$surfaces): FakeTool
    {
        $this->registry->register($tool);

        foreach ($surfaces as $surface) {
            config(["ai.tools.allowlist.{$surface->value}" => array_merge((array) config("ai.tools.allowlist.{$surface->value}"), [$tool->name()])]);
        }

        return $tool;
    }

    protected function approvedVendor(): Vendor
    {
        return Vendor::factory()->create(['is_approved' => true])->fresh();
    }

    protected function vendorContext(?Vendor $vendor = null): ToolContext
    {
        return ToolContext::forVendor($vendor ?? $this->approvedVendor());
    }

    protected function toolCall(string $name, array $arguments = [], string $id = 'call_1'): AiToolCall
    {
        return new AiToolCall($id, $name, $arguments);
    }

    protected function callTool(string $name, array $arguments, ToolContext $context, string $id = 'call_1'): ToolResult
    {
        $executor = $this->executor();

        return $executor->execute($this->toolCall($name, $arguments, $id), $context, $executor->beginTurn());
    }
}
