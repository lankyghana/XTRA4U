<?php

namespace App\Services\Ai\Developer;

use App\Services\Ai\Logging\Redactor;
use Illuminate\Support\Facades\Log;

/**
 * One typed event per assistant turn, through the existing M5.2 channel and Redactor. Like AiLogger and ToolLogger it
 * has NO parameter that could carry a prompt, an answer, a tool argument or a tool result: counts, timings, a short
 * principal hash and the request id (the correlation id the engine already issues) only.
 */
class DeveloperAssistantLogger
{
    public function turn(string $requestId, string $principalKey, int $rounds, int $toolCallsRequested, int $toolCallsRun, int $toolCallsOk, int $followUpCallsIgnored, int $answerChars, int $durationMs): void
    {
        $this->write('info', 'ai.developer_assistant.turn', [
            'request_id' => $requestId,
            'surface' => 'developer',
            'principal' => substr(hash('sha256', $principalKey), 0, 12),
            'rounds' => $rounds,
            'tool_calls_requested' => $toolCallsRequested,
            'tool_calls_run' => $toolCallsRun,
            'tool_calls_ok' => $toolCallsOk,
            'followup_calls_ignored' => $followUpCallsIgnored,
            'answer_chars' => $answerChars,
            'duration_ms' => $durationMs,
            'outcome' => 'success',
        ]);
    }

    /** A failure the assistant did not expect (not an AiException, which the engine has already logged). Class only. */
    public function unexpected(string $principalKey, string $exceptionClass): void
    {
        $this->write('warning', 'ai.developer_assistant.error', [
            'surface' => 'developer',
            'principal' => substr(hash('sha256', $principalKey), 0, 12),
            'outcome' => 'failure',
            'error_class' => $exceptionClass,
        ]);
    }

    /** @param array<string,mixed> $context */
    private function write(string $level, string $event, array $context): void
    {
        try {
            Log::channel(config('ai.logging.channel'))->{$level}($event, Redactor::context($context));
        } catch (\Throwable) {
            // Logging must never break (or reveal anything about) an assistant turn.
        }
    }
}
