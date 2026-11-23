<?php

namespace App\Services\Ai\Data;

use App\Services\Ai\Exceptions\AiInvalidRequestException;
use Illuminate\Support\Str;

/**
 * One provider-neutral request. Built by SERVER code only.
 *
 * `principalKey` is an opaque, server-derived identity used solely for rate limiting and log correlation
 * (e.g. "vendor:12", "guest:<session hash>"). It is never read from a model or from request input.
 */
final class AiRequest
{
    public readonly string $requestId;

    /**
     * @param  list<AiMessage>  $messages
     * @param  list<array<string,mixed>>  $tools  provider-neutral tool schemas (carried, not executed, in M5.2)
     */
    public function __construct(
        public readonly AiSurface $surface,
        public readonly string $principalKey,
        public readonly array $messages,
        public readonly ?string $model = null,
        public readonly ?int $maxOutputTokens = null,
        public readonly ?int $timeoutSeconds = null,
        public readonly array $tools = [],
        ?string $requestId = null,
        /** Absolute microtime(true) by which the WHOLE assistant turn (both rounds + tools) must be over; null = none. */
        public readonly ?float $deadlineAt = null,
        /**
         * The durable budget reservation (ledger row id) that pays for THIS provider attempt. Set only by the orchestrator
         * after it has reserved; an external provider adapter refuses to transmit a request whose reservation is missing,
         * closed or not its own.
         */
        public readonly ?string $reservationId = null,
    ) {
        if ($principalKey === '' || strlen($principalKey) > 128) {
            throw new AiInvalidRequestException('A principal key of 1-128 characters is required.');
        }

        if ($messages === []) {
            throw new AiInvalidRequestException('At least one message is required.');
        }

        foreach ($messages as $m) {
            if (! $m instanceof AiMessage) {
                throw new AiInvalidRequestException('Messages must be AiMessage instances.');
            }
        }

        $this->requestId = $requestId ?? strtolower((string) Str::ulid());
    }

    /** Same request with the engine's final, clamped values. */
    public function resolved(string $model, int $maxOutputTokens, int $timeoutSeconds): self
    {
        return new self($this->surface, $this->principalKey, $this->messages, $model, $maxOutputTokens, $timeoutSeconds, $this->tools, $this->requestId, $this->deadlineAt, $this->reservationId);
    }

    /** Same request, paid for by the given budget reservation. */
    public function withReservation(string $reservationId): self
    {
        return new self($this->surface, $this->principalKey, $this->messages, $this->model, $this->maxOutputTokens, $this->timeoutSeconds, $this->tools, $this->requestId, $this->deadlineAt, $reservationId);
    }

    /** Size of everything that would be sent to a provider, in bytes. */
    public function payloadBytes(): int
    {
        $bytes = 0;
        foreach ($this->messages as $m) {
            $bytes += strlen($m->content);
        }

        return $bytes + ($this->tools === [] ? 0 : strlen((string) json_encode($this->tools)));
    }
}
