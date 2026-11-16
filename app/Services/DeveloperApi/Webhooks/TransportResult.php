<?php

namespace App\Services\DeveloperApi\Webhooks;

final class TransportResult
{
    public function __construct(
        public readonly string $outcome, // success|http_error|redirect_blocked|timeout|connection_failed|tls_error|too_large|error
        public readonly ?int $statusCode,
        public readonly int $durationMs,
        public readonly ?string $error = null, // a fixed label, never raw exception text
    ) {}

    public function succeeded(): bool
    {
        return $this->outcome === 'success';
    }
}
