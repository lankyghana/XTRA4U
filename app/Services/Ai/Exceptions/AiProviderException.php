<?php

namespace App\Services\Ai\Exceptions;

/**
 * A provider call failed. Adapters must build these from SANITISED information only (a kind and a short
 * description): never from a raw HTTP exception message, request, headers or response body.
 */
class AiProviderException extends AiException
{
    public const TIMEOUT = 'timeout';

    public const UNAVAILABLE = 'unavailable';

    public const RATE_LIMITED = 'rate_limited';

    public const AUTHENTICATION = 'authentication';

    public const BAD_REQUEST = 'bad_request';

    public const INVALID_RESPONSE = 'invalid_response';

    /**
     * @param  bool  $requestSent  false ONLY when the adapter is certain nothing was transmitted (e.g. the connection
     *                             was refused). Anything uncertain must stay true: it decides whether budget is released.
     */
    public function __construct(public readonly string $kind, string $message = '', ?int $retryAfterSeconds = null, public readonly bool $requestSent = true)
    {
        parent::__construct($message !== '' ? $message : "AI provider error ({$kind}).", $retryAfterSeconds);
    }

    public static function timeout(string $message = 'The provider did not respond in time.'): self
    {
        return new self(self::TIMEOUT, $message);
    }

    public static function unavailable(string $message = 'The provider is unavailable.', bool $requestSent = true): self
    {
        return new self(self::UNAVAILABLE, $message, null, $requestSent);
    }

    public static function rateLimited(?int $retryAfter = null): self
    {
        return new self(self::RATE_LIMITED, 'The provider rate-limited the request.', $retryAfter);
    }

    public static function unexpected(): self
    {
        return new self(self::INVALID_RESPONSE, 'Unexpected failure while calling the provider.');
    }

    /** Safe to retry within the engine's retry cap (never for rate limiting: it needs time, not another try). */
    public function isRetryable(): bool
    {
        return in_array($this->kind, [self::TIMEOUT, self::UNAVAILABLE], true);
    }

    /**
     * Counts toward opening the circuit breaker: evidence the PROVIDER is unhealthy. Bad requests and
     * authentication failures prove it answered, so they do not.
     */
    public function isTransient(): bool
    {
        return in_array($this->kind, [self::TIMEOUT, self::UNAVAILABLE, self::RATE_LIMITED, self::INVALID_RESPONSE], true);
    }

    /**
     * Could the provider have processed (and therefore billed) this attempt? Budget is released only when the answer
     * is a definite no. A timeout, a 5xx after sending, a malformed reply and any foreign exception are all "maybe":
     * the request may have been generated and billed even though we never saw the reply, so the reserved estimate
     * stays charged. Rate-limit / authentication / bad-request replies are the provider refusing up front.
     */
    public function mayHaveConsumedTokens(): bool
    {
        return match ($this->kind) {
            self::RATE_LIMITED, self::AUTHENTICATION, self::BAD_REQUEST => false,
            self::UNAVAILABLE => $this->requestSent,
            default => true, // timeout, invalid_response and anything unrecognised
        };
    }

    public function errorCode(): string
    {
        return 'ai_unavailable';
    }

    public function userMessage(): string
    {
        return 'The assistant is temporarily unavailable. Please try again later.';
    }
}
