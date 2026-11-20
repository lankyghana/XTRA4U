<?php

namespace App\Services\Ai\Exceptions;

class AiBusyException extends AiException
{
    public function errorCode(): string
    {
        return 'ai_busy';
    }

    public function userMessage(): string
    {
        return 'The assistant is busy right now. Please try again shortly.';
    }
}
