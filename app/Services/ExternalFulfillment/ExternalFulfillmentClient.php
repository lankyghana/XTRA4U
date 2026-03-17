<?php

namespace App\Services\ExternalFulfillment;

use App\Models\Order;
use App\Services\ExternalFulfillment\Contracts\ExternalFulfillmentClient as ExternalFulfillmentClientContract;
use App\Services\ExternalFulfillment\ExternalFulfillmentClientFactory;

/**
 * @deprecated Use ExternalFulfillmentClientFactory + provider-specific clients instead.
 */
final class ExternalFulfillmentClient implements ExternalFulfillmentClientContract
{
    public function __construct(private ExternalFulfillmentConfig $config)
    {
    }

    public function sendOrder(Order $order, string $idempotencyKey): array
    {
        return ExternalFulfillmentClientFactory::make($this->config)
            ->sendOrder($order, $idempotencyKey);
    }

    public function getServices(): array
    {
        return ExternalFulfillmentClientFactory::make($this->config)
            ->getServices();
    }
}
