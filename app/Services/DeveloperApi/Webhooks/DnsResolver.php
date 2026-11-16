<?php

namespace App\Services\DeveloperApi\Webhooks;

/** Seam for DNS so tests (and DNS-rebinding scenarios) are fully controllable. */
interface DnsResolver
{
    /**
     * Every A and AAAA address for the host. Empty array = could not resolve.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
