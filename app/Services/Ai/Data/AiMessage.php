<?php

namespace App\Services\Ai\Data;

final class AiMessage
{
    public function __construct(
        public readonly AiRole $role,
        public readonly string $content,
    ) {}

    public static function system(string $content): self
    {
        return new self(AiRole::System, $content);
    }

    public static function user(string $content): self
    {
        return new self(AiRole::User, $content);
    }

    public static function assistant(string $content): self
    {
        return new self(AiRole::Assistant, $content);
    }
}
