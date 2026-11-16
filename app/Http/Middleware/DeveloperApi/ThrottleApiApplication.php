<?php

namespace App\Http\Middleware\DeveloperApi;

use App\Support\DeveloperApi\ApiError;
use App\Support\DeveloperApi\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-application requests/minute (application override -> plan -> default).
 * Keyed by application, not key, so rotating or minting keys cannot multiply a quota.
 */
class ThrottleApiApplication
{
    public function handle(Request $request, Closure $next): Response
    {
        $app = $request->attributes->get('api.application');
        $limit = max(1, $app->effectiveRateLimitPerMinute());
        $bucket = 'api-rate:'.$app->id;

        if (RateLimiter::tooManyAttempts($bucket, $limit)) {
            $retry = RateLimiter::availableIn($bucket);

            throw new ApiException(ApiError::RATE_LIMITED, 'Rate limit exceeded.', 429, ['retry_after_seconds' => $retry], [
                'Retry-After' => (string) $retry,
                'X-RateLimit-Limit' => (string) $limit,
                'X-RateLimit-Remaining' => '0',
            ]);
        }

        RateLimiter::hit($bucket, 60);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('X-RateLimit-Limit', (string) $limit);
        $response->headers->set('X-RateLimit-Remaining', (string) RateLimiter::remaining($bucket, $limit));

        return $response;
    }
}
