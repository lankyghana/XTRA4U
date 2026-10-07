<?php

namespace App\Services\UtilityBills\Exceptions;

/** The XTRA4U provider wallet cannot cover this bill. */
class ProviderInsufficientBalance extends UtilityProviderException
{
    public function __construct(string $message = 'ProviderInsufficientBalance', ?int $httpStatus = null)
    {
        parent::__construct($message, $httpStatus, 'insufficient_balance');
    }

    public function userMessage(): string
    {
        return 'Utility bills are temporarily unavailable. Please try again shortly.';
    }
}
