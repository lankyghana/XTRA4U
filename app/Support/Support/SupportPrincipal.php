<?php

namespace App\Support\Support;

use App\Models\Admin;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The authenticated actor of a support operation.
 *
 * It can ONLY be built from a server-side authenticated model, through the
 * named constructors below; the constructor is private and nothing here reads
 * request input. SupportService accepts nothing else as the actor, so a
 * request can never make the service act as a different vendor or as an admin.
 *
 * `guard` names the identity table the id belongs to, which keeps the two ways
 * the project represents an administrator unambiguous:
 *   vendor -> vendors.id        (vendor guard)
 *   admin  -> admins.id         (admin guard, App\Models\Admin)
 *   web    -> users.id          (default guard, App\Models\User with role=admin)
 */
final class SupportPrincipal
{
    public const GUARD_VENDOR = 'vendor';

    public const GUARD_ADMIN = 'admin';

    public const GUARD_WEB = 'web';

    public const ROLE_VENDOR = 'vendor';

    public const ROLE_ADMIN = 'admin';

    private function __construct(
        public readonly string $role,
        public readonly string $guard,
        public readonly int $id,
    ) {}

    public static function forVendor(Vendor $vendor): self
    {
        return new self(self::ROLE_VENDOR, self::GUARD_VENDOR, (int) $vendor->getKey());
    }

    public static function forAdmin(Admin|User $admin): self
    {
        if ($admin instanceof User && ($admin->role ?? null) !== 'admin') {
            throw new \InvalidArgumentException('User is not an administrator.');
        }

        return new self(
            self::ROLE_ADMIN,
            $admin instanceof Admin ? self::GUARD_ADMIN : self::GUARD_WEB,
            (int) $admin->getKey(),
        );
    }

    /** Authenticated vendor of this request (vendor guard only), or null. */
    public static function tryVendor(?Request $request = null): ?self
    {
        $vendor = Auth::guard('vendor')->user();

        return $vendor instanceof Vendor ? self::forVendor($vendor) : null;
    }

    /**
     * Authenticated administrator of this request, or null. Mirrors AdminOnly:
     * the admin guard first, then a default-guard User whose role is admin.
     */
    public static function tryAdmin(?Request $request = null): ?self
    {
        $admin = Auth::guard('admin')->user();
        if ($admin instanceof Admin) {
            return self::forAdmin($admin);
        }

        $user = Auth::user();
        if ($user instanceof User && ($user->role ?? null) === 'admin') {
            return self::forAdmin($user);
        }

        return null;
    }

    public static function vendor(?Request $request = null): self
    {
        return self::tryVendor($request) ?? abort(403, 'Unauthorized');
    }

    public static function admin(?Request $request = null): self
    {
        return self::tryAdmin($request) ?? abort(403, 'Unauthorized');
    }

    public function isVendor(): bool
    {
        return $this->role === self::ROLE_VENDOR;
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }
}
