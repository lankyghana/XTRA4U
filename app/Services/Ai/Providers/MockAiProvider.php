<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiResponse;
use App\Services\Ai\Data\AiUsage;
use App\Services\Ai\Exceptions\AiConfigurationException;
use App\Services\Ai\Exceptions\AiProviderException;

/**
 * Offline provider for local development and automated tests. It performs NO network I/O of any kind.
 *
 * Tests script it (`respondWith`, `failWith`, `queue`) and inspect it (`callCount`, `calls`). The container
 * holds one shared instance (AiServiceProvider) so a test and the engine see the same object.
 */
final class MockAiProvider implements AiProvider
{
    public const NAME = 'mock';

    /** @var list<AiResponse|\Throwable|\Closure> */
    private array $script = [];

    /** @var list<AiRequest> */
    private array $calls = [];

    public function name(): string
    {
        return self::NAME;
    }

    public function isLive(): bool
    {
        return false;
    }

    public function assertConfigured(?string $model): void
    {
        if (app()->environment('production') && ! config('ai.providers.mock.allowed_in_production', false)) {
            throw new AiConfigurationException('The mock AI provider is not allowed in production.');
        }
    }

    public function send(AiRequest $request): AiResponse
    {
        $this->calls[] = $request;

        $next = array_shift($this->script);

        if ($next instanceof \Closure) {
            $next = $next($request);
        }

        if ($next instanceof \Throwable) {
            throw $next;
        }

        if ($next instanceof AiResponse) {
            return $next;
        }

        return $this->defaultResponse($request);
    }

    /** Queue a response, an exception to throw, or a closure(AiRequest) returning either. One entry per call. */
    public function queue(AiResponse|\Throwable|\Closure ...$items): self
    {
        foreach ($items as $item) {
            $this->script[] = $item;
        }

        return $this;
    }

    public function respondWith(string $text, ?AiUsage $usage = null, string $model = 'mock-model'): self
    {
        return $this->queue(new AiResponse($text, self::NAME, $model, $usage ?? new AiUsage(10, 5)));
    }

    public function failWith(AiProviderException $e): self
    {
        return $this->queue($e);
    }

    public function timeoutOnce(): self
    {
        return $this->queue(AiProviderException::timeout());
    }

    public function callCount(): int
    {
        return count($this->calls);
    }

    /** @return list<AiRequest> */
    public function calls(): array
    {
        return $this->calls;
    }

    public function reset(): self
    {
        $this->script = [];
        $this->calls = [];

        return $this;
    }

    private function defaultResponse(AiRequest $request): AiResponse
    {
        $bytes = $request->payloadBytes();

        return new AiResponse(
            text: '[mock] Offline mock response.',
            provider: self::NAME,
            model: $request->model ?? (string) config('ai.providers.mock.model', 'mock-model'),
            usage: new AiUsage(max(1, intdiv($bytes, 4)), 8),
            providerRequestId: 'mock_'.$request->requestId,
        );
    }
}
