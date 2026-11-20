<?php

namespace App\Services\Ai\Contracts;

use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiResponse;
use App\Services\Ai\Exceptions\AiConfigurationException;
use App\Services\Ai\Exceptions\AiProviderException;

/**
 * The only thing the rest of the AI engine knows about an AI vendor.
 *
 * Contract for implementers:
 *  - `send()` makes AT MOST ONE attempt, honours `$request->timeoutSeconds`, and never retries or sleeps
 *    (the orchestrator owns retries, timeouts, budgets and the circuit breaker);
 *  - failures are thrown as AiProviderException built from sanitised information only (no headers, no
 *    credentials, no raw request/response bodies);
 *  - credentials are read lazily from config inside the adapter and never stored on a property that could be
 *    serialised, logged or dumped.
 */
interface AiProvider
{
    /** Stable provider name used for config, pricing, budgets and logs (e.g. "openai", "mock"). */
    public function name(): string;

    /** True when send() performs network I/O to a third party (live budgets are then mandatory). */
    public function isLive(): bool;

    /**
     * Fail closed when the provider cannot run (missing key, model, base URL, forbidden environment…).
     * Must not perform any network call.
     *
     * @throws AiConfigurationException
     */
    public function assertConfigured(?string $model): void;

    /**
     * @throws AiProviderException
     */
    public function send(AiRequest $request): AiResponse;
}
