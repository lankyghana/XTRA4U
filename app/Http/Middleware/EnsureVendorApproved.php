<?php

namespace App\Http\Middleware;

use App\Models\Vendor;
use Closure;
use Illuminate\Support\Facades\Auth;

class EnsureVendorApproved
{
    public function handle($request, Closure $next)
    {
        $vendorGuardUser = Auth::guard('vendor')->user();
        $user = Auth::user();

        $vendor = $vendorGuardUser instanceof Vendor
            ? $vendorGuardUser
            : ($user instanceof Vendor ? $user : ($user->vendor ?? null));

        if (!$vendor || ! $vendor->is_approved) {
            return redirect()->route('vendor.request.form')->withErrors(['access' => 'Vendor not approved.']);
        }

        return $next($request);
    }
}
