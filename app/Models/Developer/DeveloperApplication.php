<?php

namespace App\Models\Developer;

use App\Models\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * An API application belongs to exactly ONE XTRA4U vendor (`vendor_id`) for its whole life.
 * It can only ever see and sell that vendor's own catalog, and it works only while that vendor
 * account is approved. There are no marketplace-wide applications.
 *
 * Legacy columns kept for data preservation but no longer read: developer_id (the retired
 * separate developer account), production_*, vendor_link_*.
 */
class DeveloperApplication extends Model
{
    use SoftDeletes;

    public const ENV_SANDBOX = 'sandbox';

    public const ENV_PRODUCTION = 'production';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_REVOKED = 'revoked';

    protected $table = 'developer_applications';

    /**
     * Only descriptive fields are mass assignable. The owner (vendor_id), status, plan and limits are
     * set explicitly by services from authenticated context, never from request input.
     */
    protected $fillable = ['name', 'description'];

    protected $casts = [
        'suspended_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (DeveloperApplication $app): void {
            // Ownership is mandatory at creation...
            if (! $app->exists && $app->vendor_id === null) {
                throw new LogicException('An API application must belong to a vendor.');
            }

            // ...and can never be changed afterwards (no transfer, no re-assignment, no adoption).
            if ($app->exists && $app->isDirty('vendor_id')) {
                throw new LogicException('The vendor that owns an API application cannot be changed.');
            }
        });
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

    public function webhooks(): HasMany
    {
        return $this->hasMany(DeveloperWebhook::class, 'application_id');
    }

    /** The owning vendor exists and is currently approved. Read live from the vendor row. */
    public function ownerIsApproved(): bool
    {
        $vendor = $this->vendor;

        return $vendor !== null && $vendor->is_approved === true;
    }

    /**
     * May this application be used right now, by the API or the portal?
     * Needs BOTH an active application and an approved owner. Fails closed.
     */
    public function isUsable(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->ownerIsApproved();
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
