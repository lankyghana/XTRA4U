<?php

namespace App\Policies;

use App\Models\SupportConversation;
use App\Support\Support\SupportPrincipal;

/**
 * Policies here receive a SupportPrincipal (see Gate::forUser in SupportService),
 * because the project has three guards and two ways to be an admin.
 */
class SupportConversationPolicy
{
    /** A vendor sees only their own conversations; any administrator sees all. */
    public function view(SupportPrincipal $actor, SupportConversation $conversation): bool
    {
        return $actor->isAdmin()
            || ($actor->isVendor() && (int) $conversation->vendor_id === $actor->id);
    }

    /**
     * Ownership only. Closed / stale-resolved handling is a user-facing
     * validation error raised by SupportService, not a 403.
     */
    public function reply(SupportPrincipal $actor, SupportConversation $conversation): bool
    {
        return $this->view($actor, $conversation);
    }

    /** Resolve / close / reopen: administrators only. */
    public function manage(SupportPrincipal $actor, SupportConversation $conversation): bool
    {
        return $actor->isAdmin();
    }
}
