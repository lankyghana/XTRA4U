<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UssdSession extends Model
{
    use HasFactory;

    /** Serving requests. */
    public const STATUS_ACTIVE = 'active';

    /** Reached an END response. Retained, not deleted, so replays are detectable. */
    public const STATUS_ENDED = 'ended';

    /** Idle past the configured timeout. */
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'session_id',
        'vendor_id',
        'ussd_subscription_id',
        'phone_number',
        'network',
        'current_step',
        'status',
        'request_count',
        'retry_count',
        'ended_at',
        'data',
    ];

    protected $casts = [
        'data' => 'array',
        'request_count' => 'integer',
        'retry_count' => 'integer',
        'ended_at' => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(UssdSubscription::class, 'ussd_subscription_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Get a specific value from the temporary session data.
     */
    public function getData(string $key, $default = null)
    {
        return data_get($this->data, $key, $default);
    }

    /**
     * Update or set a specific value in the temporary session data.
     */
    public function updateData(string $key, $value): void
    {
        $data = $this->data ?? [];
        data_set($data, $key, $value);
        $this->data = $data;
        $this->save();
    }

    /**
     * Merge multiple key-value pairs into session data in a single DB write.
     */
    public function setDataMany(array $pairs): void
    {
        $data = $this->data ?? [];
        foreach ($pairs as $key => $value) {
            data_set($data, $key, $value);
        }
        $this->data = $data;
        $this->save();
    }
}
