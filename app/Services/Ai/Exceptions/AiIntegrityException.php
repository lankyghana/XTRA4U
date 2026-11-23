<?php

namespace App\Services\Ai\Exceptions;

/**
 * The budget accounting or the emergency stop cannot be PROVEN intact (M5.7.2). It is an AiConfigurationException, so every
 * existing handler fails closed with the same generic, safe message; the extra information is for the operator only.
 *
 *  - $mismatch = true  : two stores that must agree do not (the database moved backwards, the anchor was replaced, a record is
 *                        missing...). This is evidence of damage: the guard latches the emergency stop and requires an explicit
 *                        reconciliation.
 *  - $mismatch = false : the independent anchor could not be read or is not configured (no evidence of damage, but also no proof
 *                        of integrity). Nothing is latched; the request is simply refused.
 */
class AiIntegrityException extends AiConfigurationException
{
    public function __construct(string $message, public readonly bool $mismatch = false)
    {
        parent::__construct($message);
    }
}
