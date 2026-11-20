<?php

namespace App\Services\Ai\Data;

/** Token usage as REPORTED by the provider. Null = the provider did not report it (never guessed here). */
final class AiUsage
{
    public function __construct(
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
    ) {}

    public function isKnown(): bool
    {
        return $this->inputTokens !== null && $this->outputTokens !== null;
    }

    public function totalTokens(): ?int
    {
        return $this->isKnown() ? $this->inputTokens + $this->outputTokens : null;
    }
}
