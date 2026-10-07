<?php

namespace App\Services\UtilityBills\Exceptions;

use RuntimeException;

/**
 * Base for every KiNG FLEXY failure. Messages are written to be safe: they never
 * contain the API key, request payloads or raw provider text, so they can be
 * logged. Customer-facing copy is chosen separately via userMessage().
 */
class UtilityProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly string $errorCode = 'provider_error',
    ) {
        parent::__construct($message);
    }

    public function userMessage(): string
    {
        return 'Utility bills are temporarily unavailable. Please try again shortly.';
    }

    /**
     * True when the outcome of a POST is genuinely unknown (the provider may
     * have processed it). The caller must retry with the SAME reference.
     */
    public function isAmbiguous(): bool
    {
        return false;
    }
}
