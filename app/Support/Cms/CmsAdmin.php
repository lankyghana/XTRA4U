<?php

namespace App\Support\Cms;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * The one place that decides "is this request an administrator?" for the CMS.
 *
 * Stricter than the generic AdminOnly middleware on purpose. Accepted:
 *   - an Admin model authenticated through the `admin` guard, or
 *   - a User model authenticated through the `web` guard with role === 'admin'.
 * Nothing else: a Vendor session, a plain User, or any other authenticatable
 * that merely lacks a `role` attribute is rejected. Guards are named
 * explicitly rather than relying on the application's default guard.
 */
final class CmsAdmin
{
    public const GUARD_ADMIN = 'admin';

    public const GUARD_WEB = 'web';

    public static function resolve(): Admin|User|null
    {
        $admin = Auth::guard(self::GUARD_ADMIN)->user();
        if ($admin instanceof Admin) {
            return $admin;
        }

        $user = Auth::guard(self::GUARD_WEB)->user();
        if ($user instanceof User && $user->role === 'admin') {
            return $user;
        }

        return null;
    }

    public static function check(): bool
    {
        return self::resolve() !== null;
    }

    /** Stable "guard:id" identity stored in created_by / updated_by columns. */
    public static function actor(): ?string
    {
        $who = self::resolve();
        if ($who === null) {
            return null;
        }

        return ($who instanceof Admin ? self::GUARD_ADMIN : self::GUARD_WEB).':'.$who->getKey();
    }

    public static function actorName(): ?string
    {
        $who = self::resolve();

        return $who?->name ?: $who?->email;
    }

    /**
     * Resolve "guard:id" strings to display names with two queries at most,
     * so a listing never issues one lookup per row.
     *
     * @param  iterable<string|null>  $actors
     * @return array<string, string>
     */
    public static function names(iterable $actors): array
    {
        $ids = ['admin' => [], 'web' => []];
        foreach ($actors as $actor) {
            if (is_string($actor) && preg_match('/^(admin|web):(\d+)$/', $actor, $m)) {
                $ids[$m[1]][] = (int) $m[2];
            }
        }

        $names = [];
        if ($ids['admin']) {
            foreach (Admin::whereIn('id', array_unique($ids['admin']))->get(['id', 'name', 'email']) as $a) {
                $names['admin:'.$a->id] = $a->name ?: $a->email;
            }
        }
        if ($ids['web']) {
            foreach (User::whereIn('id', array_unique($ids['web']))->get(['id', 'name', 'email']) as $u) {
                $names['web:'.$u->id] = $u->name ?: $u->email;
            }
        }

        return $names;
    }
}
