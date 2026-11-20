<?php

namespace App\Services\Ai\Tools;

use App\Models\Vendor;
use App\Services\Ai\Data\AiSurface;
use App\Services\Ai\Tools\Exceptions\AiToolContextException;

/**
 * WHO a tool call runs for. Built only by trusted server code from an authenticated actor; it is the single source of
 * identity, ownership and authorization for every tool. The model never supplies, sees or influences any of it.
 *
 *  - The constructor is private: the only ways to get one are the three factories below, each of which takes a
 *    server-side object (an authenticated Vendor model, or the server's own session id) - not request input.
 *  - It is immutable (readonly) and cannot be unserialised, so there is no path that turns a client-supplied
 *    payload (JSON, session blob, queue payload) into a context.
 *  - Even a well-formed context is re-verified against the database on every tool call (ToolContextVerifier), so a
 *    vendor suspended mid-turn loses tool access immediately.
 *
 * Customer surface: customers are anonymous storefront visitors in this platform, so there is no customer id. The
 * "owner" of guest-scoped data is a one-way hash of the server-side session id.
 */
final class ToolContext
{
    public const CAP_VENDOR_APPROVED = 'vendor.approved';

    public const CAP_DEVELOPER_PORTAL = 'developer.portal';

    public const CAP_GUEST_SESSION = 'guest.session';

    /** @param list<string> $capabilities server-granted, never model-supplied */
    private function __construct(
        public readonly AiSurface $surface,
        public readonly ToolActorType $actorType,
        public readonly ?int $actorId,
        public readonly ?int $vendorId,
        public readonly ?string $guestKey,
        public readonly array $capabilities,
    ) {}

    /** The vendor assistant: an authenticated, approved vendor (guard `vendor`, behind `vendor.approved`). */
    public static function forVendor(Vendor $vendor): self
    {
        $id = self::approvedVendorId($vendor);

        return new self(AiSurface::Vendor, ToolActorType::Vendor, $id, $id, null, [self::CAP_VENDOR_APPROVED]);
    }

    /** The developer assistant: the approved vendor who owns the Developer API applications (portal session). */
    public static function forDeveloper(Vendor $vendor): self
    {
        $id = self::approvedVendorId($vendor);

        return new self(AiSurface::Developer, ToolActorType::Vendor, $id, $id, null, [self::CAP_VENDOR_APPROVED, self::CAP_DEVELOPER_PORTAL]);
    }

    /** The customer assistant: an anonymous visitor, identified only by a hash of the SERVER session id. */
    public static function forGuest(string $serverSessionId): self
    {
        if (strlen($serverSessionId) < 16) {
            throw new AiToolContextException('A server session id is required to build a guest tool context.');
        }

        return new self(AiSurface::Customer, ToolActorType::Guest, null, null, hash('sha256', $serverSessionId), [self::CAP_GUEST_SESSION]);
    }

    public function can(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    /** Same shape as the AiRequest principal key, so rate limiting and logs correlate. */
    public function principalKey(): string
    {
        return $this->actorType === ToolActorType::Vendor
            ? 'vendor:'.$this->actorId
            : 'guest:'.substr((string) $this->guestKey, 0, 32);
    }

    private static function approvedVendorId(Vendor $vendor): int
    {
        $id = $vendor->getKey();

        if (! $vendor->exists || ! is_int($id) || $id < 1) {
            throw new AiToolContextException('A tool context needs a persisted vendor.');
        }

        if ($vendor->is_approved !== true) {
            throw new AiToolContextException('A tool context needs an approved vendor.');
        }

        return $id;
    }

    /** A context is never reconstructed from stored or client-supplied data. */
    public function __unserialize(array $data): void
    {
        throw new AiToolContextException('A tool context cannot be unserialised.');
    }
}
