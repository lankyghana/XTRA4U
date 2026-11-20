<?php

namespace App\Services\Ai\Tools\Exceptions;

/**
 * Internal control-flow exception: the model's tool-call input was refused. Carries only a stable code and
 * parameter-level problem codes (never the offending values), and is converted to a rejected ToolResult.
 */
final class ToolInputRejected extends \RuntimeException
{
    /** @param list<array{parameter:string,problem:string}> $problems */
    public function __construct(public readonly string $reason, public readonly array $problems = [])
    {
        parent::__construct($reason);
    }
}
