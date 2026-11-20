<?php

namespace App\Services\Ai\Exceptions;

class AiCircuitOpenException extends AiException
{
    public function errorCode(): string
    {
        return 'ai_unavailable';
    }

    public function userMessage(): string
    {
        return 'The assistant is temporarily unavailable. Please try again later.';
    }
}
