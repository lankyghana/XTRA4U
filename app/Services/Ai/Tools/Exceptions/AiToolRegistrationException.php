<?php

namespace App\Services\Ai\Tools\Exceptions;

use App\Services\Ai\Exceptions\AiException;

/** A tool definition (or the registry itself) is wrong. Raised at registration time by trusted code, never by a model. */
class AiToolRegistrationException extends AiException
{
    public function errorCode(): string
    {
        return 'ai_tool_registration';
    }

    public function userMessage(): string
    {
        return 'The assistant is temporarily unavailable. Please try again later.';
    }
}
