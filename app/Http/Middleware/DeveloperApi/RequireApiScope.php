<?php

namespace App\Http\Middleware\DeveloperApi;

use App\Support\DeveloperApi\ApiError;
use App\Support\DeveloperApi\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Usage: ->middleware('api.scope:products:read') — ALL listed scopes are required. */
class RequireApiScope
{
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $key = $request->attributes->get('api.key');

        foreach ($scopes as $scope) {
            if (! $key || ! $key->hasScope($scope)) {
                throw new ApiException(ApiError::MISSING_SCOPE, "This API key lacks the required scope: {$scope}.", 403, ['required_scope' => $scope]);
            }
        }

        return $next($request);
    }
}
