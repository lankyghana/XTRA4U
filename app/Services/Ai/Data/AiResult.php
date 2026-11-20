<?php

namespace App\Services\Ai\Data;

/** What the orchestrator returns: the response plus engine-side accounting. */
final class AiResult
{
    public function __construct(
        public readonly string $requestId,
        public readonly AiResponse $response,
        public readonly int $durationMs,
        public readonly int $attempts,
        /** Integer micro-USD, or null when no reliable pricing is configured for this provider/model. */
        public readonly ?int $estimatedCostMicroUsd,
    ) {}
}
