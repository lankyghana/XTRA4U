<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\Logging\Redactor;
use Illuminate\Support\Facades\Log;

/**
 * Structured, redacted logging of tool calls. Typed fields only: there is NO parameter that can carry an argument
 * value, a result, a call id, an exception message or a prompt. The tool name is logged only when it is a well-formed
 * identifier (a model can send anything), the actor only as a short one-way hash, and exceptions only as a CLASS name.
 */
class ToolLogger
{
    public function record(
        string $toolName,
        string $surface,
        string $principalKey,
        string $status,
        ?string $errorCode,
        ?string $reason,
        int $argumentCount,
        int $resultBytes,
        int $durationMs,
        ?string $exceptionClass = null,
    ): void {
        $context = Redactor::context([
            'tool' => preg_match(ToolRegistry::NAME_PATTERN, $toolName) === 1 ? $toolName : '(invalid)',
            'surface' => $surface,
            'principal' => substr(hash('sha256', $principalKey), 0, 12),
            'status' => $status,
            'error_code' => $errorCode,
            'reason' => $reason,
            'args_n' => $argumentCount,
            'result_bytes' => $resultBytes,
            'duration_ms' => $durationMs,
            'exception_class' => $exceptionClass,
        ]);

        try {
            Log::channel(config('ai.logging.channel'))->{$status === 'ok' ? 'info' : 'warning'}('ai.tool.call', $context);
        } catch (\Throwable) {
            // Logging must never break (or reveal anything about) a tool call.
        }
    }
}
