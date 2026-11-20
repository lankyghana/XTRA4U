<?php

namespace App\Services\Ai\Logging;

use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Exceptions\AiException;
use Illuminate\Support\Facades\Log;

/**
 * Structured, redacted AI logging. The ONLY fields that can be logged are the typed ones below: request id,
 * surface, a short HASH of the principal, provider, model, error class/code, counts, durations. There is no
 * parameter through which a prompt, response, message, tool argument, header or credential could be passed,
 * and every string still goes through Redactor as a second layer. Exceptions are logged by class + redacted
 * message only (never a trace, never a chained previous exception).
 */
class AiLogger
{
    public function completed(AiRequest $r, string $provider, string $model, ?int $inputTokens, ?int $outputTokens, int $durationMs, int $attempts, ?int $costMicroUsd): void
    {
        $this->write('info', 'ai.request.completed', $r, [
            'provider' => $provider,
            'model' => $model,
            'outcome' => 'success',
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'duration_ms' => $durationMs,
            'attempts' => $attempts,
            'estimated_cost_micro_usd' => $costMicroUsd,
        ]);
    }

    /** Refused by a gate (disabled, limits, busy, circuit, config) — the provider was not called. */
    public function rejected(AiRequest $r, \Throwable $e, ?string $provider = null): void
    {
        $this->write('warning', 'ai.request.rejected', $r, [
            'provider' => $provider,
            'outcome' => 'rejected',
        ] + $this->error($e));
    }

    /** The provider was called and failed. */
    public function failed(AiRequest $r, string $provider, ?string $model, \Throwable $e, int $durationMs, int $attempts): void
    {
        $this->write('warning', 'ai.request.failed', $r, [
            'provider' => $provider,
            'model' => $model,
            'outcome' => 'failure',
            'duration_ms' => $durationMs,
            'attempts' => $attempts,
        ] + $this->error($e));
    }

    /** @return array<string,mixed> */
    private function error(\Throwable $e): array
    {
        $isAi = $e instanceof AiException;

        return [
            'error_class' => $e::class,
            'error_code' => $isAi ? $e->errorCode() : 'unexpected',
            // AiException messages are already redacted; anything else is reduced to its class only.
            'error_detail' => $isAi ? $e->getMessage() : null,
        ];
    }

    /** @param array<string,mixed> $fields */
    private function write(string $level, string $event, AiRequest $r, array $fields): void
    {
        $context = Redactor::context([
            'request_id' => $r->requestId,
            'surface' => $r->surface->value,
            // Correlation without identity: a short one-way hash of the server-derived principal key.
            'principal' => substr(hash('sha256', $r->principalKey), 0, 12),
        ] + $fields);

        try {
            Log::channel(config('ai.logging.channel'))->{$level}($event, $context);
        } catch (\Throwable) {
            // Logging must never be able to break (or reveal anything about) an AI request.
        }
    }
}
