<?php

namespace Tests\Feature\DeveloperApi;

use App\Services\DeveloperApi\Webhooks\DnsResolver;

/**
 * Fully controllable DNS for tests. No test ever touches a real resolver or network.
 * Unknown hosts resolve to nothing (fail closed), so every host a test uses must be
 * declared explicitly. Answers can be swapped between calls to simulate DNS rebinding.
 */
final class FakeDnsResolver implements DnsResolver
{
    /** @var array<string, list<string>> */
    private array $records = [];

    /** @var list<string> */
    public array $lookups = [];

    /** @param  list<string>|string  $ips */
    public function set(string $host, array|string $ips): self
    {
        $this->records[strtolower($host)] = (array) $ips;

        return $this;
    }

    public function resolve(string $host): array
    {
        $this->lookups[] = $host;

        return $this->records[strtolower($host)] ?? [];
    }
}
