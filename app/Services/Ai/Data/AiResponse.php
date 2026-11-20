<?php

namespace App\Services\Ai\Data;

/** A provider reply, normalised so no caller ever sees provider-specific JSON. */
final class AiResponse
{
    /** @param list<AiToolCall> $toolCalls */
    public function __construct(
        public readonly string $text,
        public readonly string $provider,
        public readonly string $model,
        public readonly AiUsage $usage = new AiUsage,
        public readonly array $toolCalls = [],
        public readonly string $finishReason = 'stop',
        public readonly ?string $providerRequestId = null,
    ) {}
}
