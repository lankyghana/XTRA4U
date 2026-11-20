<?php

namespace App\Services\Ai\Safety;

use Illuminate\Contracts\Cache\Lock;

/** Proof that the breaker let this request through, and whether it is the single half-open probe. */
final class CircuitPermit
{
    public function __construct(
        public readonly string $provider,
        public readonly bool $isProbe = false,
        public readonly ?Lock $probeLock = null,
    ) {}
}
