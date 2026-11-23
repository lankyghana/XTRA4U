<?php

namespace Tests\Feature\Ai\Support;

use App\Services\Ai\Integrity\AnchorException;
use App\Services\Ai\Integrity\AnchorSnapshot;
use App\Services\Ai\Integrity\IntegrityAnchor;

/**
 * Wraps a real anchor and fails on demand, to prove what happens when the anchor is unreachable or its outcome is unknown.
 *
 * `failAfterApplying` models the worst case the contract allows: the anchor DID record the change, but the caller is told it
 * failed (a timeout after the write). The caller must then treat the reservation as possibly accepted.
 */
final class FaultyAnchor implements IntegrityAnchor
{
    /** @var array<string,bool> method name => fail BEFORE applying */
    public array $failBefore = [];

    /** @var array<string,bool> method name => fail AFTER applying */
    public array $failAfter = [];

    /** @var list<string> */
    public array $calls = [];

    public function __construct(private readonly IntegrityAnchor $inner) {}

    public function configurationProblems(): array
    {
        return $this->inner->configurationProblems();
    }

    public function read(): AnchorSnapshot
    {
        return $this->through('read', fn () => $this->inner->read());
    }

    public function initialise(string $anchorId, array $records, string $killState, string $reason): AnchorSnapshot
    {
        return $this->through('initialise', fn () => $this->inner->initialise($anchorId, $records, $killState, $reason));
    }

    public function reserve(string $id, array $record, ?array $closePrevious = null): int
    {
        return $this->through('reserve', fn () => $this->inner->reserve($id, $record, $closePrevious));
    }

    public function close(string $id, string $state, int $tokens, int $cost): void
    {
        $this->through('close', fn () => $this->inner->close($id, $state, $tokens, $cost));
    }

    public function setKillSwitch(string $state, string $reason): array
    {
        return $this->through('setKillSwitch', fn () => $this->inner->setKillSwitch($state, $reason));
    }

    public function setReconciliation(string $status, string $reason): void
    {
        $this->through('setReconciliation', fn () => $this->inner->setReconciliation($status, $reason));
    }

    private function through(string $method, \Closure $do): mixed
    {
        $this->calls[] = $method;

        if ($this->failBefore[$method] ?? false) {
            throw AnchorException::unavailable('simulated anchor outage');
        }

        $result = $do();

        if ($this->failAfter[$method] ?? false) {
            throw AnchorException::unavailable('simulated anchor timeout after the change was applied');
        }

        return $result;
    }
}
