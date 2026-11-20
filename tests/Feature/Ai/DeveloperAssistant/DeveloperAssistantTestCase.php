<?php

namespace Tests\Feature\Ai\DeveloperAssistant;

use App\Models\Developer\DeveloperApiKey;
use App\Models\Developer\DeveloperApplication;
use App\Models\Vendor;
use App\Services\Ai\Data\AiResponse;
use App\Services\Ai\Data\AiToolCall;
use App\Services\Ai\Data\AiUsage;
use App\Services\Ai\Developer\DeveloperAssistant;
use App\Services\Ai\Tools\ToolContext;
use App\Services\Ai\Tools\ToolExecutor;
use App\Services\Ai\Tools\ToolResult;
use App\Services\DeveloperApi\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\Ai\AiTestCase;

/**
 * Developer Assistant tests: engine ON, developer surface ON, offline scripted mock provider, the REAL sealed tool
 * registry and the shipped allowlist, a real database. Http is faked and asserted empty in tearDown (inherited), so
 * every test also proves that nothing reached the network.
 */
abstract class DeveloperAssistantTestCase extends AiTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.tools.allowlist.developer' => DeveloperAssistant::TOOLS]);
        RateLimiter::clear('dev-assistant:ip:127.0.0.1');
    }

    protected function approvedVendor(array $attrs = []): Vendor
    {
        return Vendor::factory()->create(array_merge(['is_approved' => true], $attrs))->fresh();
    }

    protected function unapprovedVendor(): Vendor
    {
        return Vendor::factory()->create(['is_approved' => false])->fresh();
    }

    protected function makeApp(Vendor $vendor, string $name = 'Test App', array $overrides = []): DeveloperApplication
    {
        $app = new DeveloperApplication(['name' => $name]);
        $app->forceFill(array_merge(['vendor_id' => $vendor->id, 'status' => DeveloperApplication::STATUS_ACTIVE], $overrides))->save();

        return $app;
    }

    /** @return array{0:DeveloperApiKey,1:string} key + the one-time plaintext */
    protected function makeKey(DeveloperApplication $app, array $scopes = ['products:read', 'orders:read'], string $environment = 'production'): array
    {
        $r = app(ApiKeyService::class)->create($app, 'Server key', $scopes, null, null, $environment);

        return [$r['key'], $r['plaintext']];
    }

    protected function signedIn(Vendor $vendor): static
    {
        return $this->actingAs($vendor, 'vendor');
    }

    /** POST a question as the given vendor. */
    protected function ask(Vendor $vendor, string $message, array $extra = [])
    {
        return $this->signedIn($vendor)->postJson(route('developers.assistant.message'), ['message' => $message] + $extra);
    }

    protected function textResponse(string $text): AiResponse
    {
        return new AiResponse($text, 'mock', 'mock-model', new AiUsage(20, 10));
    }

    /** @param list<AiToolCall> $calls */
    protected function toolCallResponse(array $calls, string $text = ''): AiResponse
    {
        return new AiResponse($text, 'mock', 'mock-model', new AiUsage(20, 10), $calls, 'tool_calls');
    }

    protected function toolCall(string $name, array $arguments = [], string $id = 'call_1'): AiToolCall
    {
        return new AiToolCall($id, $name, $arguments);
    }

    protected function developerContext(?Vendor $vendor = null): ToolContext
    {
        return ToolContext::forDeveloper($vendor ?? $this->approvedVendor());
    }

    /** Run one tool call through the real executor, registry and allowlist. */
    protected function runTool(string $name, array $arguments, ToolContext $context, string $id = 'call_1'): ToolResult
    {
        $executor = app(ToolExecutor::class);

        return $executor->execute($this->toolCall($name, $arguments, $id), $context, $executor->beginTurn());
    }

    /** The model-facing JSON of a result, for "must not contain" assertions. */
    protected function payload(ToolResult $result): string
    {
        return $result->toModelPayload();
    }
}
