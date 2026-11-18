<?php

namespace App\Http\Middleware\DeveloperApi;

use App\Support\DeveloperApi\ApiEnvironment;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fail-closed guard for every authenticated commerce route: the environment must be established by the
 * API key (sandbox or production) before any controller runs. Replaces the Milestone 3 `api.production`
 * guard, which refused sandbox keys while no sandbox engine existed.
 */
class RequireKnownEnvironment
{
    public function handle(Request $request, Closure $next): Response
    {
        ApiEnvironment::of($request);

        return $next($request);
    }
}
