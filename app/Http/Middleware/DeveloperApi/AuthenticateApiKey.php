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
 * Fails closed: any doubt (unknown key, revoked, expired, inactive application,
 * owning vendor not approved) is a refusal. Failed attempts are rate limited per IP so keys cannot be guessed.
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

        if (! $app) {
            throw new ApiException(ApiError::APPLICATION_INACTIVE, 'This application is not active.', 403);
        }

        // THE gate: the vendor that owns this application must be approved RIGHT NOW. It is read from the
        // vendor row on every request (never cached, never trusted from when the key was issued), so a
        // suspension, withdrawn approval or deleted vendor stops the very next request.
        if (! $app->ownerIsApproved()) {
            throw new ApiException(ApiError::VENDOR_NOT_APPROVED, 'The vendor account that owns this application is not approved.', 403);
        }

        if ($app->status !== \App\Models\Developer\DeveloperApplication::STATUS_ACTIVE) {
            throw new ApiException(ApiError::APPLICATION_INACTIVE, 'This application is not active.', 403);
        }

        // Both environments are available to an approved vendor's keys; the key decides which one it
        // reaches. An unknown value (corrupt row) is refused rather than guessed.
        if (! in_array($key->environment, ['sandbox', 'production'], true)) {
            throw new ApiException(ApiError::ENVIRONMENT_MISMATCH, 'This API key is not valid.', 403);
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
