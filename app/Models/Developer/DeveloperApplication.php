<?php

namespace App\Models\Developer;

use App\Models\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeveloperApplication extends Model
{
    use SoftDeletes;

    public const ENV_SANDBOX = 'sandbox';

    public const ENV_PRODUCTION = 'production';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_REVOKED = 'revoked';

    public const VENDOR_NONE = 'none';

    public const VENDOR_PENDING = 'pending';

    public const VENDOR_APPROVED = 'approved';

    protected $table = 'developer_applications';

    /**
     * environment, status, vendor link and limits are deliberately NOT mass
     * assignable: they change only through explicit, authorised service calls
     * (admin approval, vendor consent), never from request input.
     */
    protected $fillable = ['developer_id', 'name', 'description'];

    protected $casts = [
        'production_approved_at' => 'datetime',
        'production_requested_at' => 'datetime',
        'production_reviewed_at' => 'datetime',
        'vendor_link_requested_at' => 'datetime',
        'vendor_link_reviewed_at' => 'datetime',
        'suspended_at' => 'datetime',
    ];

    public function developer(): BelongsTo
    {
        return $this->belongsTo(Developer::class, 'developer_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(DeveloperPlan::class, 'plan_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(DeveloperApiKey::class, 'application_id');
    }

    public const PRODUCTION_NONE = 'none';

    public const PRODUCTION_REQUESTED = 'requested';

    public const PRODUCTION_APPROVED = 'approved';

    public const PRODUCTION_REJECTED = 'rejected';

    public const VENDOR_REJECTED = 'rejected';

    /** Production credentials may exist/work only when an admin approved BOTH the status and the environment. */
    public function hasProductionAccess(): bool
    {
        return $this->environment === self::ENV_PRODUCTION
            && $this->production_status === self::PRODUCTION_APPROVED
            && $this->status === self::STATUS_ACTIVE;
    }

    public function isProduction(): bool
    {
        return $this->environment === self::ENV_PRODUCTION;
    }

    /** May this application call the API at all right now? */
    public function isUsable(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->developer
            && $this->developer->isActive();
    }

    /**
     * A vendor-bound application is restricted to ONE vendor's offers, and only
     * once that link has been approved. Until then it must see nothing rather
     * than silently fall back to the whole marketplace.
     */
    public function isVendorBound(): bool
    {
        return $this->vendor_id !== null;
    }

    public function vendorScopeIsActive(): bool
    {
        return $this->isVendorBound() && $this->vendor_link_status === self::VENDOR_APPROVED;
    }

    public function effectiveRateLimitPerMinute(): int
    {
        if ($this->rate_limit_per_minute) {
            return (int) $this->rate_limit_per_minute;
        }

        if ($this->plan && $this->plan->is_active) {
            return (int) $this->plan->requests_per_minute;
        }

        return (int) config('developer_api.rate_limit.default_per_minute', 60);
    }
}
