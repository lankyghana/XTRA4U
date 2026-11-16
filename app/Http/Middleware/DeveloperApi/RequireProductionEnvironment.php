<?php

namespace App\Http\Middleware\DeveloperApi;

use App\Support\DeveloperApi\ApiError;
use App\Support\DeveloperApi\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fail-closed guard for routes that only have a production implementation.
 * A sandbox key can never reach production services, even by mistake: until the
 * sandbox engine serves a route, the route refuses sandbox keys outright.
 */
class RequireProductionEnvironment
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get('api.environment') !== 'production') {
            throw new ApiException(ApiError::SANDBOX_UNAVAILABLE, 'This endpoint is not yet available in the sandbox environment.', 501);
        }

        return $next($request);
    }
}
