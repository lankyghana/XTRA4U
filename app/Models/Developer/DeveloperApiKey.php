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
        return in_array($scope, (array) $this->scopes, true);
    }
}
