<?php

namespace App\Services\Ai\Exceptions;

class AiDisabledException extends AiException
{
    public function errorCode(): string
    {
        return 'ai_disabled';
    }

    public function userMessage(): string
    {
        return 'The assistant is not available right now.';
    }
}
