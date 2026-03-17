<?php

namespace App\Services\ExternalFulfillment;

use App\Services\ExternalFulfillment\Contracts\ExternalFulfillmentClient;
use App\Services\ExternalFulfillment\Providers\DatafyhubClient;
use App\Services\ExternalFulfillment\Providers\XpresPortalClient;
use InvalidArgumentException;

final class ExternalFulfillmentClientFactory
{
    public static function make(ExternalFulfillmentConfig $config): ExternalFulfillmentClient
    {
        $provider = $config->provider ?? 'datafyhub';

        return match ($provider) {
            'xpresportal' => new XpresPortalClient($config),
            'datafyhub' => new DatafyhubClient($config),
            default => throw new InvalidArgumentException("Unsupported external fulfillment provider [{$provider}]."),
        };
    }
}
