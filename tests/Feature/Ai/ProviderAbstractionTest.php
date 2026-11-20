<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Data\AiMessage;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiResponse;
use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Data\AiToolCall;
use App\Services\Ai\Data\AiUsage;
use App\Services\Ai\Exceptions\AiConfigurationException;
use App\Services\Ai\Exceptions\AiInvalidRequestException;
use App\Services\Ai\Exceptions\AiProviderException;

class ProviderAbstractionTest extends AiTestCase
{
    public function test_mock_provider_success_returns_a_normalised_response(): void
    {
        $result = $this->engine()->run($this->req('Hello there'));

        $this->assertSame('mock', $result->response->provider);
        $this->assertSame('mock-model', $result->response->model);
        $this->assertSame('stop', $result->response->finishReason);
        $this->assertSame([], $result->response->toolCalls);
        $this->assertTrue($result->response->usage->isKnown());
        $this->assertSame($result->response->usage->inputTokens + $result->response->usage->outputTokens, $result->response->usage->totalTokens());
        $this->assertSame(1, $result->attempts);
        $this->assertNotSame('', $result->requestId);
        $this->assertNull($result->estimatedCostMicroUsd, 'no pricing is configured, so cost must be unknown, not guessed');
        $this->assertSame(1, $this->mockProvider()->callCount());
    }

    public function test_scripted_responses_are_returned_in_order_with_their_usage(): void
    {
        $this->mockProvider()->respondWith('first', new AiUsage(7, 3))->respondWith('second');

        $a = $this->engine()->run($this->req());
        $b = $this->engine()->run($this->req());

        $this->assertSame('first', $a->response->text);
        $this->assertSame(10, $a->response->usage->totalTokens());
        $this->assertSame('second', $b->response->text);
    }

    public function test_the_provider_is_told_the_engines_clamped_limits_not_the_callers(): void
    {
        $this->engine()->run($this->req('Hello', options: ['timeoutSeconds' => 999, 'maxOutputTokens' => 99999]));

        $sent = $this->mockProvider()->calls()[0];
        $this->assertSame(30, $sent->timeoutSeconds, 'timeout is clamped to ai.request.max_timeout_seconds');
        $this->assertSame(512, $sent->maxOutputTokens, 'output allowance is clamped to ai.request.max_output_tokens');
        $this->assertSame('mock-model', $sent->model);
    }

    public function test_defaults_apply_when_the_caller_asks_for_nothing(): void
    {
        $this->engine()->run($this->req());

        $sent = $this->mockProvider()->calls()[0];
        $this->assertSame(20, $sent->timeoutSeconds);
        $this->assertSame(512, $sent->maxOutputTokens);
    }

    public function test_a_provider_error_surfaces_as_a_safe_ai_exception(): void
    {
        $this->mockProvider()->failWith(AiProviderException::unavailable());

        try {
            $this->engine()->run($this->req());
            $this->fail('Expected a provider failure.');
        } catch (AiProviderException $e) {
            $this->assertSame(AiProviderException::UNAVAILABLE, $e->kind);
            $this->assertSame('ai_unavailable', $e->errorCode());
            $this->assertStringNotContainsString('provider', strtolower($e->userMessage()), 'end users must not learn about the provider');
        }
    }

    public function test_provider_error_kinds_are_classified_for_retry_and_circuit_breaking(): void
    {
        $this->assertTrue(AiProviderException::timeout()->isRetryable());
        $this->assertTrue(AiProviderException::unavailable()->isRetryable());
        $this->assertFalse(AiProviderException::rateLimited()->isRetryable());
        $this->assertFalse((new AiProviderException(AiProviderException::BAD_REQUEST))->isRetryable());

        $this->assertTrue(AiProviderException::timeout()->isTransient());
        $this->assertTrue(AiProviderException::rateLimited()->isTransient());
        $this->assertFalse((new AiProviderException(AiProviderException::BAD_REQUEST))->isTransient());
        $this->assertFalse((new AiProviderException(AiProviderException::AUTHENTICATION))->isTransient());
    }

    public function test_tool_calls_are_carried_back_but_never_executed_in_this_milestone(): void
    {
        $this->mockProvider()->queue(new AiResponse('', 'mock', 'mock-model', new AiUsage(5, 5), [new AiToolCall('call_1', 'some.tool', ['x' => 1])], 'tool_calls'));

        $result = $this->engine()->run($this->req());

        $this->assertCount(1, $result->response->toolCalls);
        $this->assertSame('some.tool', $result->response->toolCalls[0]->name);
        $this->assertSame('tool_calls', $result->response->finishReason);
    }

    public function test_the_mock_provider_is_refused_in_production_unless_explicitly_allowed(): void
    {
        $this->app['env'] = 'production';

        try {
            $this->engine()->run($this->req());
            $this->fail('Expected the mock to be refused in production.');
        } catch (AiConfigurationException $e) {
            $this->assertSame('ai_misconfigured', $e->errorCode());
        }

        $this->assertSame(0, $this->mockProvider()->callCount());
    }

    public function test_request_objects_reject_missing_identity_and_empty_conversations(): void
    {
        $this->expectException(AiInvalidRequestException::class);
        new AiRequest(AiSurface::Vendor, '', [AiMessage::user('hi')]);
    }

    public function test_request_objects_reject_an_empty_message_list(): void
    {
        $this->expectException(AiInvalidRequestException::class);
        new AiRequest(AiSurface::Vendor, 'vendor:1', []);
    }

    public function test_oversized_requests_and_contexts_are_rejected_before_the_provider(): void
    {
        config(['ai.request.max_request_bytes' => 10]);
        try {
            $this->engine()->run($this->req(str_repeat('x', 50)));
            $this->fail('Expected rejection.');
        } catch (AiInvalidRequestException) {
        }

        config(['ai.request.max_request_bytes' => 16000, 'ai.request.max_context_messages' => 2]);
        $big = new AiRequest(AiSurface::Vendor, 'vendor:1', [AiMessage::user('a'), AiMessage::assistant('b'), AiMessage::user('c')]);
        try {
            $this->engine()->run($big);
            $this->fail('Expected rejection.');
        } catch (AiInvalidRequestException) {
        }

        $this->assertSame(0, $this->mockProvider()->callCount());
        $this->assertSame(0, $this->usage()['global']['requests'], 'rejected requests consume no budget');
    }
}
