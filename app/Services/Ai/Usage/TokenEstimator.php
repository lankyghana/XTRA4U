<?php

namespace App\Services\Ai\Usage;

use App\Services\Ai\Data\AiRequest;

/**
 * A deliberately PESSIMISTIC token estimate used only to reserve budget before the call (bytes / 3 rounds up
 * for English and over-counts, which is the safe direction), plus the full output allowance. The real figure
 * replaces it when the provider reports usage. It is an estimate, never billed or shown as fact.
 */
class TokenEstimator
{
    public function estimate(AiRequest $resolved): int
    {
        return (int) ceil($resolved->payloadBytes() / 3) + (int) $resolved->maxOutputTokens;
    }
}
