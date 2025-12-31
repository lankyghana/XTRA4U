<?php

namespace App\Http\Middleware;

use App\Models\Vendor;
use Closure;
use Illuminate\Support\Facades\Auth;

class EnsureVendorApproved
{
    public function handle($request, Closure $next)
    {
        $vendor = Auth::guard('vendor')->user();

        // Not logged in as vendor: send to vendor login.
        if (!($vendor instanceof Vendor)) {
            return redirect()->route('vendor.login.form');
        }

        // Logged in but not approved (e.g. suspended after login): force logout and return to login.
        if (!$vendor->is_approved) {
            Auth::guard('vendor')->logout();

            return redirect()
                ->route('vendor.login.form')
                ->withErrors(['access' => 'Your vendor account is not approved.']);
        }

        return $next($request);
    }
}
