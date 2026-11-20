<?php

namespace App\Services\Ai\Tools;

use App\Models\Vendor;
use App\Services\Ai\Data\AiSurface;

/**
 * Re-checks a ToolContext on EVERY tool call: its internal consistency, and (for vendor-backed contexts) that the
 * vendor still exists and is still approved right now. This is a read of trusted server state, not of anything the
 * model said. Fails closed: any doubt or error means "not valid".
 */
class ToolContextVerifier
{
    public function verify(ToolContext $context): bool
    {
        try {
            return match ($context->surface) {
                AiSurface::Customer => $this->guestIsConsistent($context),
                AiSurface::Vendor, AiSurface::Developer => $this->vendorIsConsistent($context) && $this->vendorIsStillApproved((int) $context->vendorId),
            };
        } catch (\Throwable) {
            return false;
        }
    }

    private function guestIsConsistent(ToolContext $c): bool
    {
        return $c->actorType === ToolActorType::Guest
            && $c->actorId === null
            && $c->vendorId === null
            && is_string($c->guestKey)
            && preg_match('/^[a-f0-9]{64}$/D', $c->guestKey) === 1
            && $c->can(ToolContext::CAP_GUEST_SESSION);
    }

    private function vendorIsConsistent(ToolContext $c): bool
    {
        return $c->actorType === ToolActorType::Vendor
            && is_int($c->actorId) && $c->actorId > 0
            && $c->vendorId === $c->actorId
            && $c->guestKey === null
            && $c->can(ToolContext::CAP_VENDOR_APPROVED)
            && ($c->surface !== AiSurface::Developer || $c->can(ToolContext::CAP_DEVELOPER_PORTAL));
    }

    private function vendorIsStillApproved(int $vendorId): bool
    {
        return Vendor::query()->whereKey($vendorId)->where('is_approved', true)->exists();
    }
}
