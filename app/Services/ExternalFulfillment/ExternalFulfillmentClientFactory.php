<?php

namespace App\Services\ExternalFulfillment;

use App\Services\ExternalFulfillment\Contracts\ExternalFulfillmentClient;
use App\Services\ExternalFulfillment\Providers\DatafyhubClient;
use App\Services\ExternalFulfillment\Providers\GigshubClient;
use App\Services\ExternalFulfillment\Providers\SkdataplugClient;
use App\Services\ExternalFulfillment\Providers\XpresPortalClient;
use InvalidArgumentException;

final class ExternalFulfillmentClientFactory
{
    public static function make(ExternalFulfillmentConfig $config, ?string $providerOverride = null): ExternalFulfillmentClient
    {
        $provider = $providerOverride ?? $config->provider ?? 'datafyhub';

        return match ($provider) {
            'xpresportal' => new XpresPortalClient($config),
            'datafyhub'   => new DatafyhubClient($config),
            'gigshub'     => new GigshubClient($config),
            'skdataplug'  => new SkdataplugClient($config),
            default       => throw new InvalidArgumentException("Unsupported external fulfillment provider [{$provider}]."),
        };
    }
}
