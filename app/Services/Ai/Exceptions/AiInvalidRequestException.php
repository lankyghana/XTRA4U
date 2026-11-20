<?php

namespace App\Services\Ai\Exceptions;

class AiInvalidRequestException extends AiException
{
    public function errorCode(): string
    {
        return 'ai_invalid_request';
    }

    public function userMessage(): string
    {
        return 'That request could not be processed.';
    }
}
