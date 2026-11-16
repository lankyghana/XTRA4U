<?php

namespace App\Services\DeveloperApi\Webhooks;

interface WebhookTransport
{
    /**
     * POST the body to a VALIDATED destination, connecting only to its pinned IP.
     * Never follows redirects; never reads more than a bounded amount of response.
     *
     * @param  array<string,string>  $headers
     *
     * @throws UnsafeWebhookDestination when a safe transport cannot be guaranteed
     */
    public function send(ValidatedDestination $destination, array $headers, string $body): TransportResult;
}
