<?php

namespace App\Services\Ai\Exceptions;

class AiRateLimitedException extends AiException
{
    public function errorCode(): string
    {
        return 'ai_rate_limited';
    }

    public function userMessage(): string
    {
        return 'You are sending messages too quickly. Please wait a moment and try again.';
    }
}
