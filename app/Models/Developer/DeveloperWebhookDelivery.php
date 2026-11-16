<?php

namespace App\Models\Developer;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeveloperWebhookDelivery extends Model
{
    public const PENDING = 'pending';

    public const DELIVERING = 'delivering';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    protected $table = 'developer_webhook_deliveries';

    protected $guarded = [];

    protected $casts = [
        'next_attempt_at' => 'datetime',
        'claimed_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(DeveloperWebhook::class, 'webhook_id')->withTrashed();
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(DeveloperEvent::class, 'event_id');
    }

    public function attemptLog(): HasMany
    {
        return $this->hasMany(DeveloperWebhookAttempt::class, 'delivery_id')->orderBy('attempt_no');
    }
}
