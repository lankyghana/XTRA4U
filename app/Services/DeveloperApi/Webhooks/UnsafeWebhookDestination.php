<?php

namespace App\Services\DeveloperApi\Webhooks;

use RuntimeException;

/** Thrown when a webhook destination cannot be PROVEN safe. The message is developer-safe. */
final class UnsafeWebhookDestination extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
