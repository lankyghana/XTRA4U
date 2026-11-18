<?php

namespace App\Http\Middleware\DeveloperApi;

use App\Support\DeveloperApi\ApiEnvironment;
use App\Support\DeveloperApi\ApiError;
use App\Support\DeveloperApi\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Sandbox-only routes (simulation). A production (xt_live_) key is refused before the controller exists. */
class RequireSandboxEnvironment
{
    public function handle(Request $request, Closure $next): Response
    {
        if (ApiEnvironment::of($request) !== ApiEnvironment::SANDBOX) {
            throw new ApiException(ApiError::ENVIRONMENT_MISMATCH, 'This endpoint is only available with a sandbox (xt_test_) API key.', 403);
        }

        return $next($request);
    }
}
