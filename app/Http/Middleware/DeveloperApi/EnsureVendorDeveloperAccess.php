<?php

namespace App\Http\Middleware\DeveloperApi;

use App\Models\Vendor;
use App\Services\DeveloperApi\VendorApiAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Developer Portal gate. There is NO developer account: the portal recognises the vendor that is
 * signed in through the platform's existing `vendor` guard.
 *
 *  - not signed in as a vendor        -> the existing vendor login (and back here afterwards);
 *  - signed in but not (or no longer) approved -> a clear "approval required" page, never the portal;
 *  - approved vendor                  -> through.
 *
 * Approval is read from the vendor row on every request (the session guard reloads the vendor each
 * time), so a suspension takes effect on the very next click.
 */
class EnsureVendorDeveloperAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $vendor = Auth::guard('vendor')->user();

        if (! ($vendor instanceof Vendor)) {
            return redirect()->guest(route('vendor.login.form'));
        }

        if (! VendorApiAccess::isEligible($vendor)) {
            return response()->view('developers.access-required', ['vendor' => $vendor], 403);
        }

        return $next($request);
    }
}
