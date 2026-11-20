<?php

namespace App\Services\Ai\Tools\Exceptions;

use App\Services\Ai\Exceptions\AiException;

/** A ToolContext could not be built from the authenticated actor (not approved, not persisted, malformed). */
class AiToolContextException extends AiException
{
    public function errorCode(): string
    {
        return 'ai_tool_context_invalid';
    }

    public function userMessage(): string
    {
        return 'That request could not be processed.';
    }
}
