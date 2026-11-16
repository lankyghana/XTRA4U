<?php

namespace App\Http\Middleware\DeveloperApi;

use App\Models\Developer\Developer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Developer-realm gate. Mirrors EnsureVendorApproved: wrong realm or a suspended account never gets in. */
class EnsureDeveloperAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        $developer = Auth::guard('developer')->user();

        if (! ($developer instanceof Developer)) {
            return redirect()->guest(route('developers.login'));
        }

        if (! $developer->isActive()) {
            Auth::guard('developer')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('developers.login')->withErrors(['email' => 'This developer account is suspended. Please contact support.']);
        }

        return $next($request);
    }
}
