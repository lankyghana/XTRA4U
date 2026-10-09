<?php

namespace App\Services\UtilityBills\Exceptions;

/** The provider returned a response in an unexpected shape. */
class ProviderMalformedResponse extends UtilityProviderException
{
    public function __construct(string $message = 'ProviderMalformedResponse', ?int $httpStatus = null)
    {
        parent::__construct($message, $httpStatus, 'malformed');
    }

    public function userMessage(): string
    {
        return 'Utility bills are temporarily unavailable. Please try again shortly.';
    }
}
