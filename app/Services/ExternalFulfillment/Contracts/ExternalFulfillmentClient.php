<?php

namespace App\Services\ExternalFulfillment\Contracts;

use App\Models\Order;

interface ExternalFulfillmentClient
{
    /**
     * Send order to external provider.
     *
     * @return array{success:bool,status?:string,external_reference?:string|null,message?:string|null,raw?:mixed}
     */
    public function sendOrder(Order $order, string $idempotencyKey): array;

    /**
     * Fetch available services/packages from external provider.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getServices(): array;
}
