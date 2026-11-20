<?php

namespace App\Services\DeveloperApi;

use App\Models\Developer\DeveloperApiKey;
use App\Models\Developer\DeveloperApplication;
use App\Support\DeveloperApi\ApiError;
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
    public function __construct(private readonly VendorApiAccess $access) {}

    public const FORMAT = '/^xt_(test|live)_([a-z0-9]{8})_([A-Za-z0-9]{40})$/';

    /**
     * @param  list<string>  $scopes
     * @return array{key: DeveloperApiKey, plaintext: string}
     */
    public function create(DeveloperApplication $app, string $name, array $scopes, ?CarbonInterface $expiresAt = null, ?int $rotatedFromId = null, ?string $environment = null): array
    {
        $scopes = $this->validateScopes($scopes);
        $environment ??= DeveloperApplication::ENV_SANDBOX; // least privilege unless production is asked for

        // Sandbox-only scopes (sandbox:simulate) can never be granted to a production key.
        $sandboxOnly = array_values(array_intersect($scopes, (array) config('developer_api.sandbox_only_scopes', [])));
        if ($environment !== DeveloperApplication::ENV_SANDBOX && $sandboxOnly !== []) {
            throw ApiException::unprocessable('INVALID_SCOPES', 'Sandbox-only scopes cannot be granted to a production key.', ['sandbox_only' => $sandboxOnly]);
        }

        // The ONLY gate for credentials of either environment: the owning vendor is approved and the
        // application is active. Pending, rejected, suspended or deleted vendors can never mint a key.
        if ($reason = $this->access->denialReason($app)) {
            throw new ApiException(
                $app->ownerIsApproved() ? ApiError::APPLICATION_INACTIVE : ApiError::VENDOR_NOT_APPROVED,
                $reason,
                403
            );
        }

        if (! in_array($environment, [DeveloperApplication::ENV_SANDBOX, DeveloperApplication::ENV_PRODUCTION], true)) {
            throw ApiException::unprocessable('INVALID_ENVIRONMENT', 'Unknown key environment.');
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

            // A rotated key is a NEW key, so it cannot carry a scope that is not currently grantable
            // (`developer_api.unavailable_scopes`, e.g. `ai:chat`, which has no endpoint). ONLY those scopes are
            // dropped; any other scope is carried unchanged and still validated by create(). The old key keeps its
            // stored scopes until it is revoked.
            $unavailable = (array) config('developer_api.unavailable_scopes', []);
            $carried = array_values(array_diff((array) $old->scopes, $unavailable));

            if ($carried === []) {
                // Nothing to put on a replacement. Refuse BEFORE touching anything: the old key is not revoked,
                // not changed, and no new key exists.
                throw ApiException::unprocessable(
                    'ROTATION_NOT_POSSIBLE',
                    'This key cannot be rotated because none of its scopes can be granted to a new key. '
                    .'The original key has not been changed and remains active. Create a new key with the scopes you need, '
                    .'and revoke the original explicitly if you want to retire it.'
                );
            }

            $new = $this->create($app, $old->name, $carried, $old->expires_at, $old->id, $old->environment);

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

        $key = DeveloperApiKey::query()->with('application.vendor', 'application.plan')->where('prefix', $m[2])->first();

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
     * Scopes that may be granted to a newly created key: every declared scope except those that have
     * no working endpoint yet (`developer_api.unavailable_scopes`). Value = human description.
     *
     * @return array<string,string>
     */
    public static function availableScopes(): array
    {
        return array_diff_key(
            (array) config('developer_api.scopes', []),
            array_flip((array) config('developer_api.unavailable_scopes', []))
        );
    }

    /**
     * @param  list<string>  $scopes
     * @return list<string>
     */
    public function validateScopes(array $scopes): array
    {
        $allowed = array_keys(self::availableScopes());
        $scopes = array_values(array_unique($scopes));
        $unknown = array_values(array_diff($scopes, $allowed));

        if ($scopes === [] || $unknown !== []) {
            throw ApiException::unprocessable('INVALID_SCOPES', 'One or more requested scopes are invalid.', ['unknown' => $unknown]);
        }

        return $scopes;
    }
}
