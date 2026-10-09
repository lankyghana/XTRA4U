<?php

namespace App\Services\UtilityBills\Exceptions;

/** The provider could not find the account or order (404). */
class ProviderNotFound extends UtilityProviderException
{
    public function __construct(string $message = 'ProviderNotFound', ?int $httpStatus = null)
    {
        parent::__construct($message, $httpStatus, 'not_found');
    }

    public function userMessage(): string
    {
        return 'We could not find that account. Please check the details and try again.';
    }
}
