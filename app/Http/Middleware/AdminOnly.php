<?php
namespace App\Http\Middleware;
use App\Support\AdminAccess;
use Closure;

class AdminOnly
{
    public function handle($request, Closure $next)
    {
        // Fail closed: an Admin on the admin guard, or a web User whose role is
        // exactly "admin" (see App\Support\AdminAccess). A missing/NULL/unknown
        // role is NOT admin.
        if (AdminAccess::check()) {
            return $next($request);
        }

        // Signed in on the web guard as a non-admin: forbidden. Anyone else
        // (anonymous, or a session on another realm such as a vendor): sign in.
        if (AdminAccess::isSignedInNonAdmin()) {
            abort(403, 'Unauthorized');
        }

        return redirect()->route('admin.login');
    }
}
