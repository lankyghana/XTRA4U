<?php

namespace App\Services\Ai\Exceptions;

class AiBudgetExceededException extends AiException
{
    public function errorCode(): string
    {
        return 'ai_budget_exhausted';
    }

    public function userMessage(): string
    {
        return 'The assistant is not available right now.';
    }
}
