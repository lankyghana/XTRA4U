<?php

namespace App\Services\UtilityBills\Exceptions;

/** The provider (or its upstream biller) returned a 5xx error. */
class ProviderServerError extends UtilityProviderException
{
    public function __construct(string $message = 'ProviderServerError', ?int $httpStatus = null)
    {
        parent::__construct($message, $httpStatus, 'upstream');
    }

    public function userMessage(): string
    {
        return 'Utility bills are temporarily unavailable. Please try again shortly.';
    }

    /** A 5xx on POST /pay may still have been processed upstream: retry only with the SAME reference. */
    public function isAmbiguous(): bool
    {
        return true;
    }
}
