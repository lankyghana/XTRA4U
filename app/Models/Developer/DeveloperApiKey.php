<?php

namespace App\Models\Developer;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeveloperApiKey extends Model
{
    protected $table = 'developer_api_keys';

    // key_hash / environment / scopes are only ever set by ApiKeyService.
    protected $fillable = [];

    protected $hidden = ['key_hash'];

    protected $casts = [
        'scopes' => 'array',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(DeveloperApplication::class, 'application_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function status(): string
    {
        return $this->isRevoked() ? 'revoked' : ($this->isExpired() ? 'expired' : 'active');
    }

    public function hasScope(string $scope): bool
    {
        // Defence in depth: a sandbox-only scope never counts on a key that is not a sandbox key,
        // whatever its stored scope list says.
        if (in_array($scope, (array) config('developer_api.sandbox_only_scopes', []), true)
            && $this->environment !== DeveloperApplication::ENV_SANDBOX) {
            return false;
        }

        return in_array($scope, (array) $this->scopes, true);
    }
}
