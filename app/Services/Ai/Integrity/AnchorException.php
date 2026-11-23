<?php

namespace App\Services\Ai\Integrity;

/** The independent anchor cannot be used. Every case means the same to callers: fail closed. */
class AnchorException extends \RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';

    public const UNAVAILABLE = 'unavailable';

    public const MISSING = 'missing';

    public const CORRUPT = 'corrupt';

    public const CONFLICT = 'conflict';

    public function __construct(public readonly string $kind, string $message)
    {
        parent::__construct($message);
    }

    public static function notConfigured(string $message = 'No independent integrity anchor is configured.'): self
    {
        return new self(self::NOT_CONFIGURED, $message);
    }

    public static function unavailable(string $message): self
    {
        return new self(self::UNAVAILABLE, $message);
    }

    public static function missing(string $message): self
    {
        return new self(self::MISSING, $message);
    }

    public static function corrupt(string $message): self
    {
        return new self(self::CORRUPT, $message);
    }

    public static function conflict(string $message): self
    {
        return new self(self::CONFLICT, $message);
    }
}
