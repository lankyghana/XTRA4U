<?php

namespace App\Services\Ai\Exceptions;

use App\Services\Ai\Logging\Redactor;

/**
 * Base of every error the AI engine raises on purpose.
 *
 * Two guarantees that matter for privacy:
 *  - the message is passed through Redactor, so a credential/phone number accidentally put in it is masked;
 *  - NO previous exception is chained: a provider/HTTP client exception can carry request headers (including
 *    the API key) and must never travel with ours into a log, a report or a stack trace.
 *
 * `userMessage()` is the only text that may ever be shown to an end user.
 */
abstract class AiException extends \RuntimeException
{
    public function __construct(string $message = '', public readonly ?int $retryAfterSeconds = null)
    {
        parent::__construct(Redactor::redact($message));
    }

    /** Stable machine-readable code (safe to log and to return to a client). */
    abstract public function errorCode(): string;

    /** Safe, generic text for end users. */
    abstract public function userMessage(): string;
}
