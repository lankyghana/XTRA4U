<?php

namespace App\Services\UtilityBills\Exceptions;

/**
 * 409 on /pay: the provider rejected a payment for the same biller + account +
 * amount made within its 30-second duplicate window. Per the provider docs a
 * new reference does NOT bypass the window. Nothing was charged: wait it out
 * and retry with the SAME reference.
 */
class ProviderDuplicateWindow extends UtilityProviderException
{
    public function __construct(string $message = 'Provider duplicate-payment window.', ?int $httpStatus = 409)
    {
        parent::__construct($message, $httpStatus, 'duplicate_window');
    }

    public function userMessage(): string
    {
        return 'Utility bills are busy right now. Please try again shortly.';
    }
}
