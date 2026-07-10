<?php

namespace App\Policies;

use App\Models\UssdPlan;

/**
 * All USSD plan management is admin-only.
 *
 * The actor is not always an App\Models\User: admins may authenticate on the
 * dedicated `admin` guard (App\Models\Admin), which carries no `role` column.
 * The null-coalesce mirrors App\Http\Middleware\AdminOnly exactly, so the two
 * never disagree about who counts as an admin.
 */
class UssdPlanPolicy
{
    private function isAdmin(mixed $user): bool
    {
        return $user !== null && ($user->role ?? 'admin') === 'admin';
    }

    public function viewAny(mixed $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(mixed $user, UssdPlan $plan): bool
    {
        return $this->isAdmin($user);
    }

    public function create(mixed $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(mixed $user, UssdPlan $plan): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * A plan with paid/active/suspended subscriptions must never be removed:
     * the restrictOnDelete foreign key on ussd_subscriptions would reject the
     * hard delete, and soft-deleting it would hide a plan vendors still hold.
     */
    public function delete(mixed $user, UssdPlan $plan): bool
    {
        return $this->isAdmin($user) && ! $plan->hasLiveSubscriptions();
    }

    public function toggleStatus(mixed $user, UssdPlan $plan): bool
    {
        return $this->isAdmin($user);
    }
}
