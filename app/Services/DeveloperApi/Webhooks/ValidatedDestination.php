<?php

namespace App\Services\DeveloperApi\Webhooks;

/**
 * A destination that passed validation, bound to the exact public IP that the
 * connection MUST use. Delivering to anything else (e.g. re-resolving the host)
 * would reopen the DNS-rebinding window.
 */
final class ValidatedDestination
{
    /** @param list<string> $ips every address the host resolved to (all public) */
    public function __construct(
        public readonly string $url,
        public readonly string $host,
        public readonly int $port,
        public readonly array $ips,
        public readonly string $pinnedIp,
    ) {}
}
