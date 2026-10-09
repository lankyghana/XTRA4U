<?php

namespace App\Services\UtilityBills\Exceptions;

/** The Commission Services API key is missing or malformed. */
class NotConfigured extends UtilityProviderException
{
    public function __construct(string $message = 'NotConfigured', ?int $httpStatus = null)
    {
        parent::__construct($message, $httpStatus, 'not_configured');
    }

    public function userMessage(): string
    {
        return 'Utility bills are not available right now.';
    }
}
