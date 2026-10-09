<?php

namespace App\Services\UtilityBills\Exceptions;

/** The provider rejected the API key (401/403). */
class ProviderUnauthorized extends UtilityProviderException
{
    public function __construct(string $message = 'ProviderUnauthorized', ?int $httpStatus = null)
    {
        parent::__construct($message, $httpStatus, 'auth');
    }

    public function userMessage(): string
    {
        return 'Utility bills are not available right now.';
    }
}
