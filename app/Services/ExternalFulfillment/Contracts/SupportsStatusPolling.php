<?php

namespace App\Services\ExternalFulfillment\Contracts;

/**
 * Implemented by provider clients that can be asked "what happened to this
 * order?" after submission.
 *
 * Polling is the safety net for providers whose webhooks may be missed, and the
 * only sync channel for providers that have no webhook at all. A provider gains
 * status sync by implementing this interface and being listed under
 * config('external_fulfillment.polling.pollable') — no changes to the poller.
 */
interface SupportsStatusPolling
{
    /**
     * Fetch the provider's current status for a previously submitted order.
     *
     * Implementations must return the provider's own raw status string; the
     * translation to local state lives in config('external_fulfillment.status_map')
     * so provider vocabularies stay declarative.
     *
     * @param  string  $remoteReference  The provider-side order identifier.
     * @return array{success:bool,status:?string,message?:string|null}
     */
    public function fetchRemoteStatus(string $remoteReference): array;
}
