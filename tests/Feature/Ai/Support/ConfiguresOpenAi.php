<?php

namespace Tests\Feature\Ai\Support;

use App\Services\Ai\Providers\OpenAiProvider;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/**
 * Shared setup for tests that drive the REAL OpenAI adapter against Http::fake(). Every value here is a throw-away
 * fixture: the key is not a credential and nothing in these tests can leave the process (stray requests throw).
 */
trait ConfiguresOpenAi
{
    protected const FAKE_KEY = 'sk-test-ZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZ';

    protected const MODEL = 'test-model-x';

    protected function allowsFakedHttp(): bool
    {
        return true;
    }

    /** A fully configured live OpenAI setup: every gate satisfied, budgets and pricing present, cache verdict faked. */
    protected function configureOpenAi(array $overrides = []): void
    {
        $this->liveBudgets();
        $this->fakeCacheEngine();

        config([
            'ai.provider' => 'openai',
            'ai.default_model' => self::MODEL,
            'ai.providers.openai.api_key' => self::FAKE_KEY,
            'ai.external_processing' => [
                'approved' => true,
                'approval_reference' => 'PRIV-REVIEW-2026-001',
                'approved_by' => 'Test Approver',
                'approved_on' => '2026-01-15',
            ],
            'ai.pricing.openai.'.self::MODEL => ['input_usd_per_million' => 2.0, 'output_usd_per_million' => 6.0, 'as_of' => '2026-01-01'],
            'ai.request.timeout_seconds' => 15,
            'ai.request.max_timeout_seconds' => 20,
            'ai.request.deadline_seconds' => 20,
            'ai.request.turn_deadline_seconds' => 40,
            'ai.request.max_output_tokens' => 512,
            'ai.request.max_retries' => 0,
        ]);

        config($overrides);

        Http::preventStrayRequests();
    }

    /** @return array<string,mixed> a successful Responses-API body */
    protected function okBody(string $text = 'Hello from the model', array $over = []): array
    {
        return array_replace_recursive([
            'id' => 'resp_test123',
            'object' => 'response',
            'status' => 'completed',
            'model' => 'provider-chosen-snapshot-name',
            'output' => [[
                'type' => 'message',
                'id' => 'msg_1',
                'role' => 'assistant',
                'status' => 'completed',
                'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]],
            ]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 40, 'total_tokens' => 140],
        ], $over);
    }

    /** @return array<string,mixed> */
    protected function functionCallItem(string $name, array|string $arguments = [], string $callId = 'call_abc'): array
    {
        return [
            'type' => 'function_call',
            'id' => 'fc_1',
            'call_id' => $callId,
            'name' => $name,
            'arguments' => is_string($arguments) ? $arguments : json_encode($arguments),
            'status' => 'completed',
        ];
    }

    protected function fakeOpenAi(array|string|\Closure $response, int $status = 200, array $headers = []): void
    {
        $handler = $response instanceof \Closure ? $response : Http::response($response, $status, $headers);

        // AiTestCase registers a catch-all Http::fake() (first match wins), so start from a fresh factory.
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([OpenAiProvider::ENDPOINT => $handler]);
    }

    /** @return list<HttpRequest> */
    protected function sent(): array
    {
        return Http::recorded()->map(fn ($pair) => $pair[0])->all();
    }

    /** @return array<string,mixed> */
    protected function bodyOf(HttpRequest $request): array
    {
        return json_decode($request->body(), true) ?: [];
    }

    /** A transport failure shaped like the one Laravel raises for a cURL error. */
    protected function connectionFailure(?int $errno, array $extra = []): ConnectionException
    {
        $guzzle = new ConnectException(
            'cURL error '.($errno ?? 0).': details that must never be surfaced',
            new Request('POST', OpenAiProvider::ENDPOINT),
            null,
            $errno === null ? [] : ['errno' => $errno] + $extra
        );

        return new ConnectionException($guzzle->getMessage(), 0, $guzzle);
    }
}
