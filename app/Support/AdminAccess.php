<?php

namespace App\Support;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;

/**
 * The single, fail-closed answer to "is this request an administrator?".
 *
 * Authorized, and nothing else:
 *   1. an {@see Admin} authenticated through the `admin` guard; or
 *   2. a {@see User} authenticated through the `web` guard whose `role` is
 *      EXACTLY the string "admin".
 *
 * Everything else is refused: vendors, resellers, customers, a User whose role
 * is NULL / empty / missing / differently cased / unknown, any other model that
 * merely lacks a `role` attribute, and anonymous visitors. Admin privilege is
 * never inferred from the ABSENCE of a role.
 *
 * Guards are named explicitly, never taken from the application's default
 * guard, so a session on one realm can never be mistaken for another.
 */
final class AdminAccess
{
    public const GUARD_ADMIN = 'admin';

    public const GUARD_WEB = 'web';

    public const ROLE_ADMIN = 'admin';

    /** The authenticated administrator for this request, or null. */
    public static function resolve(): Admin|User|null
    {
        $admin = Auth::guard(self::GUARD_ADMIN)->user();
        if ($admin instanceof Admin) {
            return $admin;
        }

        // The web guard only ever yields an admin for a User with the exact admin
        // role. (An Admin model on the web guard is the wrong realm: not accepted.)
        $user = Auth::guard(self::GUARD_WEB)->user();
        if ($user instanceof User && $user->role === self::ROLE_ADMIN) {
            return $user;
        }

        return null;
    }

    public static function check(): bool
    {
        return self::resolve() !== null;
    }

    /**
     * Policy-style check on an already-resolved actor (e.g. the user a Gate or
     * Form Request was handed). Same rule as {@see resolve()}.
     */
    public static function isAdmin(?Authenticatable $actor): bool
    {
        if ($actor instanceof Admin) {
            return true;
        }

        return $actor instanceof User && $actor->role === self::ROLE_ADMIN;
    }

    /** True when someone is signed in on the web guard but is not an administrator. */
    public static function isSignedInNonAdmin(): bool
    {
        return Auth::guard(self::GUARD_WEB)->check() && ! self::check();
    }
}
