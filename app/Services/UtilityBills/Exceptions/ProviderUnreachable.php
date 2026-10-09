<?php

namespace App\Services\UtilityBills\Exceptions;

/** The provider did not answer in time or the connection failed. */
class ProviderUnreachable extends UtilityProviderException
{
    public function __construct(string $message = 'ProviderUnreachable', ?int $httpStatus = null)
    {
        parent::__construct($message, $httpStatus, 'timeout');
    }

    public function userMessage(): string
    {
        return 'Utility bills are temporarily unavailable. Please try again shortly.';
    }

    public function isAmbiguous(): bool
    {
        return true;
    }
}
