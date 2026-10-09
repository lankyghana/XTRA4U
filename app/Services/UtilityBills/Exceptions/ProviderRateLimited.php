<?php

namespace App\Services\UtilityBills\Exceptions;

/** Provider or local rate limit reached. */
class ProviderRateLimited extends UtilityProviderException
{
    public function __construct(string $message = 'ProviderRateLimited', ?int $httpStatus = null)
    {
        parent::__construct($message, $httpStatus, 'rate_limited');
    }

    /** True when OUR budget refused the call before anything was sent to the provider. */
    public bool $local = false;

    /** Seconds until the local budget frees up (local refusals only). */
    public int $retryAfter = 0;

    public static function local(int $retryAfter): self
    {
        $e = new self('Local provider rate budget exhausted.');
        $e->local = true;
        $e->retryAfter = max(1, $retryAfter);

        return $e;
    }

    public function userMessage(): string
    {
        return 'Utility bills are busy right now. Please try again in a minute.';
    }
}
