<?php

namespace App\Services\DeveloperApi;

use App\Models\Developer\DeveloperApiKey;
use App\Models\Developer\DeveloperApplication;
use App\Support\DeveloperApi\ApiException;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues, rotates, revokes and resolves API keys.
 *
 * Key shape:  xt_{test|live}_{prefix8}_{secret40}
 *   - prefix is stored in clear (lookup + display);
 *   - only sha256(fullKey) is stored. The secret is 40 random alphanumerics
 *     (~238 bits), so a fast hash is appropriate and enables an indexed lookup;
 *   - the plaintext is returned exactly once, by create()/rotate().
 */
class ApiKeyService
{
    public const FORMAT = '/^xt_(test|live)_([a-z0-9]{8})_([A-Za-z0-9]{40})$/';

    /**
     * @param  list<string>  $scopes
     * @return array{key: DeveloperApiKey, plaintext: string}
     */
    public function create(DeveloperApplication $app, string $name, array $scopes, ?CarbonInterface $expiresAt = null, ?int $rotatedFromId = null, ?string $environment = null): array
    {
        $scopes = $this->validateScopes($scopes);
        $environment ??= $app->environment;

        if ($app->status !== DeveloperApplication::STATUS_ACTIVE) {
            throw ApiException::unprocessable('APPLICATION_INACTIVE', 'Keys can only be issued for an active application.');
        }

        if (! in_array($environment, [DeveloperApplication::ENV_SANDBOX, DeveloperApplication::ENV_PRODUCTION], true)) {
            throw ApiException::unprocessable('INVALID_ENVIRONMENT', 'Unknown key environment.');
        }

        // Production credentials only ever exist for an application an admin has approved.
        if ($environment === DeveloperApplication::ENV_PRODUCTION && ! $app->hasProductionAccess()) {
            throw ApiException::unprocessable('PRODUCTION_NOT_APPROVED', 'Production access has not been approved for this application.');
        }

        $active = $app->apiKeys()->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->count();

        // A rotation revokes the old key in the same transaction, so it is allowed to
        // momentarily sit at the cap.
        if ($rotatedFromId === null && $active >= (int) config('developer_api.keys.max_active_per_application', 5)) {
            throw ApiException::unprocessable('KEY_LIMIT_REACHED', 'This application has reached its maximum number of active API keys.');
        }

        $expiresAt ??= now()->addDays((int) config('developer_api.keys.default_expiry_days', 365));
        $mode = $environment === DeveloperApplication::ENV_PRODUCTION ? 'live' : 'test';

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $prefix = Str::lower(Str::random(8));
            $secret = Str::random(40);
            $plaintext = "xt_{$mode}_{$prefix}_{$secret}";

            try {
                $key = new DeveloperApiKey;
                $key->forceFill([
                    'application_id' => $app->id,
                    'name' => $name,
                    'prefix' => $prefix,
                    'key_hash' => $this->hash($plaintext),
                    'environment' => $environment,
                    'scopes' => $scopes,
                    'expires_at' => $expiresAt,
                    'rotated_from_id' => $rotatedFromId,
                ])->save();

                return ['key' => $key, 'plaintext' => $plaintext];
            } catch (QueryException $e) {
                // Prefix collision (1 in ~2.8e12) — try again with a fresh prefix.
                if ($attempt === 2) {
                    throw $e;
                }
            }
        }

        throw new \LogicException('unreachable');
    }

    /**
     * Replace a key: a new key with the same name/scopes is issued and the old one
     * revoked atomically. Returns the new key and its one-time plaintext.
     *
     * @return array{key: DeveloperApiKey, plaintext: string}
     */
    public function rotate(DeveloperApiKey $old): array
    {
        return DB::transaction(function () use ($old) {
            $old = DeveloperApiKey::query()->lockForUpdate()->findOrFail($old->id);
            $app = $old->application;

            $new = $this->create($app, $old->name, (array) $old->scopes, $old->expires_at, $old->id, $old->environment);

            if (! $old->isRevoked()) {
                $old->forceFill(['revoked_at' => now()])->save();
            }

            return $new;
        });
    }

    public function revoke(DeveloperApiKey $key): void
    {
        if (! $key->isRevoked()) {
            $key->forceFill(['revoked_at' => now()])->save();
        }
    }

    public function revokeAllForApplication(DeveloperApplication $app): void
    {
        $app->apiKeys()->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    /** Resolve a presented credential to its key record, or null. Never distinguishes "no such prefix" from "wrong secret". */
    public function resolve(?string $presented): ?DeveloperApiKey
    {
        if (! is_string($presented) || ! preg_match(self::FORMAT, $presented, $m)) {
            return null;
        }

        $key = DeveloperApiKey::query()->with('application.developer', 'application.plan')->where('prefix', $m[2])->first();

        if (! $key || ! hash_equals((string) $key->key_hash, $this->hash($presented))) {
            return null;
        }

        return $key;
    }

    public function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /**
     * @param  list<string>  $scopes
     * @return list<string>
     */
    public function validateScopes(array $scopes): array
    {
        $allowed = array_keys(config('developer_api.scopes', []));
        $scopes = array_values(array_unique($scopes));
        $unknown = array_values(array_diff($scopes, $allowed));

        if ($scopes === [] || $unknown !== []) {
            throw ApiException::unprocessable('INVALID_SCOPES', 'One or more requested scopes are invalid.', ['unknown' => $unknown]);
        }

        return $scopes;
    }
}
