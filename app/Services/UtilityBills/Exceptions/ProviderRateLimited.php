<?php

namespace App\Services\UtilityBills\Exceptions;

/** Provider or local rate limit reached. */
class ProviderRateLimited extends UtilityProviderException
{
    public function __construct(string $message = 'ProviderRateLimited', ?int $httpStatus = null)
    {
        parent::__construct($message, $httpStatus, 'rate_limited');
    }

    public function userMessage(): string
    {
        return 'Utility bills are busy right now. Please try again in a minute.';
    }
}
