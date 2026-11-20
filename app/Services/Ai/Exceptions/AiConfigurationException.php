<?php

namespace App\Services\Ai\Exceptions;

class AiConfigurationException extends AiException
{
    public function errorCode(): string
    {
        return 'ai_misconfigured';
    }

    public function userMessage(): string
    {
        return 'The assistant is not available right now.';
    }
}
