<?php

namespace App\Services\Ai\Safety;

use Illuminate\Contracts\Cache\Lock;

/** A held concurrency slot. A *borrowed* slot belongs to the enclosing assistant turn and is never released here. */
final class ConcurrencySlot
{
    private function __construct(private readonly ?Lock $lock) {}

    public static function owning(Lock $lock): self
    {
        return new self($lock);
    }

    public static function borrowed(): self
    {
        return new self(null);
    }

    public function release(): void
    {
        $this->lock?->release();
    }
}
