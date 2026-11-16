<?php

namespace App\Http\Middleware\DeveloperApi;

use App\Models\Developer\DeveloperApiKey;
use App\Services\DeveloperApi\ApiKeyService;
use App\Support\DeveloperApi\ApiError;
use App\Support\DeveloperApi\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the API key and binds the request to its application.
 *
 * Fails closed: any doubt (unknown key, revoked, expired, suspended
 * application or developer, key/application environment disagreement) is a
 * refusal. Failed attempts are rate limited per IP so keys cannot be guessed.
 */
class AuthenticateApiKey
{
    public function __construct(private readonly ApiKeyService $keys) {}

    public function handle(Request $request, Closure $next): Response
    {
        $failBucket = 'api-auth-fail:'.$request->ip();
        $maxFailures = (int) config('developer_api.rate_limit.auth_failures_per_minute', 20);

        if (RateLimiter::tooManyAttempts($failBucket, $maxFailures)) {
            throw new ApiException(ApiError::RATE_LIMITED, 'Too many failed authentication attempts.', 429, [], [
                'Retry-After' => (string) RateLimiter::availableIn($failBucket),
            ]);
        }

        $key = $this->keys->resolve($this->presentedCredential($request));

        try {
            $this->assertUsable($key);
        } catch (ApiException $e) {
            RateLimiter::hit($failBucket, 60);
            throw $e;
        }

        $app = $key->application;

        $request->attributes->set('api.key', $key);
        $request->attributes->set('api.application', $app);
        // The KEY decides the environment, never the application: a sandbox key can never reach production.
        $request->attributes->set('api.environment', $key->environment);

        $this->touchLastUsed($key);

        return $next($request);
    }

    private function presentedCredential(Request $request): ?string
    {
        $bearer = $request->bearerToken();

        return $bearer ?: $request->header('X-API-Key');
    }

    private function assertUsable(?DeveloperApiKey $key): void
    {
        if (! $key) {
            throw new ApiException(ApiError::INVALID_API_KEY, 'Invalid or missing API credentials.', 401);
        }

        if ($key->isRevoked()) {
            throw new ApiException(ApiError::API_KEY_REVOKED, 'This API key has been revoked.', 401);
        }

        if ($key->isExpired()) {
            throw new ApiException(ApiError::API_KEY_EXPIRED, 'This API key has expired.', 401);
        }

        $app = $key->application;

        if (! $app || ! $app->isUsable()) {
            throw new ApiException(ApiError::APPLICATION_INACTIVE, 'This application is not active.', 403);
        }

        // Sandbox keys always work (they can only reach the sandbox). A PRODUCTION key works only
        // while the application still holds approved production access: demotion or suspension
        // of production access kills those keys instantly, regardless of their expiry.
        $valid = match ($key->environment) {
            'sandbox' => true,
            'production' => $app->hasProductionAccess(),
            default => false,
        };

        if (! $valid) {
            throw new ApiException(ApiError::ENVIRONMENT_MISMATCH, 'This API key is not valid for this application\'s current access level.', 403);
        }
    }

    /** At most once a minute per key, and without model events. */
    private function touchLastUsed(DeveloperApiKey $key): void
    {
        if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute())) {
            DeveloperApiKey::query()->whereKey($key->id)->update(['last_used_at' => now()]);
        }
    }
}
