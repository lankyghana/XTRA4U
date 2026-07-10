<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class UssdSubscription extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const STATUS_PENDING_PAYMENT = 'pending_payment';

    public const STATUS_PAID = 'paid';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING_PAYMENT,
        self::STATUS_PAID,
        self::STATUS_ACTIVE,
        self::STATUS_EXPIRED,
        self::STATUS_SUSPENDED,
        self::STATUS_CANCELLED,
    ];

    /**
     * Statuses that occupy the vendor's single subscription slot. A row in one
     * of these states must carry current_for_vendor_id = vendor_id; the unique
     * index on that column is what makes "one live subscription per vendor" a
     * database guarantee rather than a check-then-insert race.
     */
    public const LIVE_STATUSES = [
        self::STATUS_PAID,
        self::STATUS_ACTIVE,
        self::STATUS_SUSPENDED,
    ];

    /** Usage percentages that trigger a one-time vendor alert. */
    public const USAGE_ALERT_THRESHOLDS = [80, 90, 95, 100];

    protected $fillable = [
        'vendor_id',
        'ussd_plan_id',
        'status',
        'ussd_code',
        'extension_code',
        'price_paid',
        'total_sessions',
        'carried_over_sessions',
        'used_sessions',
        'payment_reference',
        'payment_gateway',
        'gateway_response',
        'starts_at',
        'expires_at',
        'activated_at',
        'cancelled_at',
        'superseded_by_id',
        'notified_thresholds',
        'current_for_vendor_id',
        'metadata',
    ];

    protected $casts = [
        'price_paid' => 'float',
        'total_sessions' => 'integer',
        'carried_over_sessions' => 'integer',
        'used_sessions' => 'integer',
        'gateway_response' => 'array',
        'notified_thresholds' => 'array',
        'metadata' => 'array',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'activated_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(UssdPlan::class, 'ussd_plan_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(UssdSubscriptionEvent::class);
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    public function scopeForVendor(Builder $query, int $vendorId): Builder
    {
        return $query->where('vendor_id', $vendorId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /** Active subscriptions whose expiry has passed but which are not yet marked expired. */
    public function scopeDueForExpiry(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_PAID])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    public function getRemainingSessionsAttribute(): int
    {
        return max(0, $this->total_sessions - $this->used_sessions);
    }

    public function getUsagePercentageAttribute(): float
    {
        if ($this->total_sessions <= 0) {
            return 100.0;
        }

        return round(min(100, ($this->used_sessions / $this->total_sessions) * 100), 2);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function hasRemainingSessions(): bool
    {
        return $this->remaining_sessions > 0;
    }

    /**
     * The single question the USSD runtime asks before serving a request.
     */
    public function isUsable(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && ! $this->isExpired()
            && $this->hasRemainingSessions();
    }

    public function occupiesSlot(): bool
    {
        return in_array($this->status, self::LIVE_STATUSES, true);
    }

    /**
     * Build the dialled code for a vendor: <base><extension>*<vendor_id>#
     * e.g. "*203*" + "45" + "*102#" => "*203*45*102#"
     *
     * Uniqueness is structural — vendors.id is unique, so two vendors on the
     * same plan can never collide, and one vendor switching plans changes the
     * extension. The unique index on ussd_code is a second line of defence.
     */
    public static function buildUssdCode(string $baseCode, string $extensionCode, int $vendorId): string
    {
        $base = rtrim(trim($baseCode), '#');
        if (! str_ends_with($base, '*')) {
            $base .= '*';
        }

        return $base.$extensionCode.'*'.$vendorId.'#';
    }

    /**
     * Thresholds crossed by current usage that have not yet been notified.
     *
     * @return array<int, int>
     */
    public function pendingUsageAlerts(): array
    {
        $notified = $this->notified_thresholds ?? [];
        $percentage = $this->usage_percentage;

        return array_values(array_filter(
            self::USAGE_ALERT_THRESHOLDS,
            fn (int $threshold) => $percentage >= $threshold && ! in_array($threshold, $notified, true)
        ));
    }

    public function markThresholdNotified(int $threshold): void
    {
        $notified = $this->notified_thresholds ?? [];
        if (! in_array($threshold, $notified, true)) {
            $notified[] = $threshold;
            sort($notified);
            $this->forceFill(['notified_thresholds' => $notified])->save();
        }
    }

    public function getExpiryWarningSent(): bool
    {
        return (bool) data_get($this->metadata, 'expiry_warning_sent', false);
    }

    /**
     * Scoped to this subscription: a renewal creates a new row, so the vendor
     * is warned again on the next cycle rather than being silenced forever.
     */
    public function markExpiryWarningSent(): void
    {
        $metadata = $this->metadata ?? [];
        $metadata['expiry_warning_sent'] = true;
        $this->forceFill(['metadata' => $metadata])->save();
    }

    public function remainingDays(): int
    {
        if ($this->expires_at === null) {
            return 0;
        }

        return max(0, (int) Carbon::now()->startOfDay()->diffInDays($this->expires_at->startOfDay(), false));
    }
}
