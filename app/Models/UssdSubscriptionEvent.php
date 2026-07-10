<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UssdSubscriptionEvent extends Model
{
    use HasFactory;

    // Lifecycle
    public const PLAN_PURCHASE_INITIATED = 'plan_purchase_initiated';

    public const PAYMENT_VERIFIED = 'payment_verified';

    public const PAYMENT_FAILED = 'payment_failed';

    public const SUBSCRIPTION_ACTIVATED = 'subscription_activated';

    public const SUBSCRIPTION_RENEWED = 'subscription_renewed';

    public const SUBSCRIPTION_UPGRADED = 'subscription_upgraded';

    public const SUBSCRIPTION_EXPIRED = 'subscription_expired';

    public const SUBSCRIPTION_SUSPENDED = 'subscription_suspended';

    public const SUBSCRIPTION_CANCELLED = 'subscription_cancelled';

    public const USSD_CODE_GENERATED = 'ussd_code_generated';

    // Runtime
    public const SESSION_CONSUMED = 'session_consumed';

    public const SESSIONS_EXHAUSTED = 'sessions_exhausted';

    public const INVALID_USSD_REQUEST = 'invalid_ussd_request';

    public const SECURITY_EVENT = 'security_event';

    // Administration
    public const ADMIN_PLAN_CREATED = 'admin_plan_created';

    public const ADMIN_PLAN_UPDATED = 'admin_plan_updated';

    public const ADMIN_PLAN_DELETED = 'admin_plan_deleted';

    public const ADMIN_SETTINGS_UPDATED = 'admin_settings_updated';

    protected $fillable = [
        'ussd_subscription_id',
        'vendor_id',
        'ussd_plan_id',
        'event',
        'description',
        'context',
        'actor_type',
        'actor_id',
        'ip_address',
    ];

    protected $casts = [
        'context' => 'array',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(UssdSubscription::class, 'ussd_subscription_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(UssdPlan::class, 'ussd_plan_id');
    }
}
