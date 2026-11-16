<?php

namespace App\Support\DeveloperApi;

use RuntimeException;

/** A deliberate, client-safe API error. The message and details are shown to the caller. */
class ApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 400,
        public readonly array $details = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $what = 'Resource'): self
    {
        return new self(ApiError::NOT_FOUND, "{$what} not found.", 404);
    }

    public static function forbidden(string $message = 'This API key is not permitted to perform this action.'): self
    {
        return new self(ApiError::FORBIDDEN, $message, 403);
    }

    public static function conflict(string $code, string $message, array $details = []): self
    {
        return new self($code, $message, 409, $details);
    }

    public static function unprocessable(string $code, string $message, array $details = []): self
    {
        return new self($code, $message, 422, $details);
    }
}
