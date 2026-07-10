<?php

namespace App\Http\Controllers\Admin\Concerns;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Admin routes may authenticate on either the `admin` guard or the `web` guard
 * with role=admin (see App\Http\Middleware\AdminOnly). Because Gate resolves
 * its user from the *default* guard, `$this->authorize()` silently sees null
 * for admin-guard sessions. Always authorize through the resolved actor.
 */
trait InteractsWithAdminGate
{
    protected function adminUser(): mixed
    {
        return Auth::guard('admin')->user() ?: Auth::user();
    }

    /**
     * @throws AuthorizationException
     */
    protected function authorizeAdmin(string $ability, mixed $arguments = []): void
    {
        Gate::forUser($this->adminUser())->authorize($ability, $arguments);
    }

    protected function adminActorId(): ?int
    {
        return $this->adminUser()?->id;
    }
}
