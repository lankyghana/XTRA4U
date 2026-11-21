<?php

namespace App\Services\Ai\VendorAssistant;

/** What one assistant turn produced. Plain data; nothing here is ever stored. */
final class VendorAssistantReply
{
    /** @param list<string> $toolsUsed distinct names of tools that ran and returned data */
    public function __construct(
        public readonly string $answer,
        public readonly array $toolsUsed,
        public readonly string $requestId,
    ) {}
}
