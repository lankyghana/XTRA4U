<?php

namespace App\Services\Ai\Data;

/**
 * A tool call the model asked for, normalised. M5.2 only CARRIES these; nothing executes them until the tool
 * system (M5.3) exists, and nothing here is ever trusted as authorization.
 */
final class AiToolCall
{
    /** @param array<string,mixed> $arguments */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $arguments = [],
    ) {}
}
