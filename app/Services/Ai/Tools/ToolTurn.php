<?php

namespace App\Services\Ai\Tools;

/**
 * Bookkeeping for ONE assistant turn: how many tool calls the model has made, which call ids it has used, and how
 * much time tools have consumed. Created by trusted code (ToolExecutor::beginTurn) for each turn; the model cannot
 * reset or extend it. Rejected calls count too, so a model cannot loop on invalid calls either.
 */
final class ToolTurn
{
    private int $calls = 0;

    private float $toolSeconds = 0.0;

    /** @var array<string,true> */
    private array $callIds = [];

    public function calls(): int
    {
        return $this->calls;
    }

    public function toolSeconds(): float
    {
        return $this->toolSeconds;
    }

    /** Count one more call; returns the call's ordinal (1-based). */
    public function admit(): int
    {
        return ++$this->calls;
    }

    /** @return bool false if this id was already used in the turn */
    public function claimId(string $id): bool
    {
        if (isset($this->callIds[$id])) {
            return false;
        }

        return $this->callIds[$id] = true;
    }

    public function addSeconds(float $seconds): void
    {
        $this->toolSeconds += max(0.0, $seconds);
    }
}
