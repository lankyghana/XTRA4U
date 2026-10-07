<?php

namespace App\Services\UtilityBills\Exceptions;

use RuntimeException;

/** A NEW sale may not start (service off, biller off, provider says no, key missing...). */
class SaleNotAllowed extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'unavailable')
    {
        parent::__construct($message);
    }
}
