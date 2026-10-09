<?php

namespace App\Services\UtilityBills\Exceptions;

/** The provider reports utility bills or this biller as disabled (503). */
class ProviderServiceDisabled extends UtilityProviderException
{
    public function __construct(string $message = 'ProviderServiceDisabled', ?int $httpStatus = null)
    {
        parent::__construct($message, $httpStatus, 'disabled');
    }

    public function userMessage(): string
    {
        return 'This bill service is currently unavailable.';
    }
}
