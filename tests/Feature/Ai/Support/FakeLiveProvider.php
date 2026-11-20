<?php

namespace Tests\Feature\Ai\Support;

use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiResponse;
use App\Services\Ai\Data\AiUsage;

/**
 * Stands in for a "live" provider (isLive() = true) so the live-only rules — mandatory budgets, mandatory model —
 * can be tested. It performs NO network I/O: it only counts calls and returns a canned response.
 */
final class FakeLiveProvider implements AiProvider
{
    public int $calls = 0;

    public function name(): string
    {
        return 'fakelive';
    }

    public function isLive(): bool
    {
        return true;
    }

    public function assertConfigured(?string $model): void {}

    public function send(AiRequest $request): AiResponse
    {
        $this->calls++;

        return new AiResponse('live ok', 'fakelive', (string) $request->model, new AiUsage(100, 50));
    }
}
