<?php

namespace App\Services\Ai\Integrity;

/**
 * The default: no independent anchor exists. Every operation fails closed, so nothing that needs integrity protection can
 * run. This is what production gets until an operator provides an independent location (docs/ai/06 section 19).
 */
final class UnconfiguredIntegrityAnchor implements IntegrityAnchor
{
    public function configurationProblems(): array
    {
        return ['no independent integrity anchor is configured (AI_INTEGRITY_ANCHOR_DRIVER)'];
    }

    public function read(): AnchorSnapshot
    {
        throw AnchorException::notConfigured();
    }

    public function initialise(string $anchorId, array $records, string $killState, string $reason): AnchorSnapshot
    {
        throw AnchorException::notConfigured();
    }

    public function reserve(string $id, array $record, ?array $closePrevious = null): int
    {
        throw AnchorException::notConfigured();
    }

    public function close(string $id, string $state, int $tokens, int $cost): void
    {
        throw AnchorException::notConfigured();
    }

    public function setKillSwitch(string $state, string $reason): array
    {
        throw AnchorException::notConfigured();
    }

    public function setReconciliation(string $status, string $reason): void
    {
        throw AnchorException::notConfigured();
    }
}
