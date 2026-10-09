<?php

namespace App\Services\UtilityBills\Exceptions;

/** The provider rejected the request (400/409). */
class ProviderRejected extends UtilityProviderException
{
    public function __construct(string $message = 'ProviderRejected', ?int $httpStatus = null)
    {
        parent::__construct($message, $httpStatus, 'rejected');
    }

    public function userMessage(): string
    {
        return 'We could not process this request. Please check the details and try again.';
    }
}
