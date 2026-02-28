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
}
