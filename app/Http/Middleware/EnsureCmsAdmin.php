<?php

namespace App\Http\Middleware;

use App\Support\Cms\CmsAdmin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for every CMS route. Anonymous visitors are sent to the admin login;
 * any authenticated non-admin (vendors included) gets a 403.
 */
class EnsureCmsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (CmsAdmin::check()) {
            return $next($request);
        }

        $anyone = auth('admin')->check() || auth('web')->check() || auth('vendor')->check();

        if (! $anyone) {
            return $request->expectsJson()
                ? abort(401)
                : redirect()->route('admin.login');
        }

        abort(403, 'Unauthorized');
    }
}
