<?php

namespace App\Models\Developer;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * External developer account. A FOURTH auth realm, separate from customer,
 * vendor and admin — never reuse Vendor/Admin/User for API consumers.
 */
class Developer extends Authenticatable
{
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    protected $table = 'developers';

    protected $fillable = ['name', 'email', 'company', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function applications(): HasMany
    {
        return $this->hasMany(DeveloperApplication::class, 'developer_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
