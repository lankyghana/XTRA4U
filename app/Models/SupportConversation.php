<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sensitive columns (vendor_id, status, queue timestamps, resolver) are NOT
 * mass assignable: only SupportService writes them, via forceFill, from the
 * authenticated principal.
 */
class SupportConversation extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_WAITING_ADMIN = 'waiting_admin';

    public const STATUS_WAITING_VENDOR = 'waiting_vendor';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_OPEN => 'Open',
        self::STATUS_WAITING_ADMIN => 'Waiting for Admin',
        self::STATUS_WAITING_VENDOR => 'Waiting for Vendor',
        self::STATUS_RESOLVED => 'Resolved',
        self::STATUS_CLOSED => 'Closed',
    ];

    protected $guarded = ['*'];

    protected $casts = [
        'first_vendor_message_at' => 'datetime',
        'last_vendor_message_at' => 'datetime',
        'last_admin_message_at' => 'datetime',
        'last_message_at' => 'datetime',
        'waiting_since' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'has_image' => 'boolean',
        'has_voice' => 'boolean',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(SupportCategory::class, 'category_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'conversation_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SupportConversationEvent::class, 'conversation_id');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function isResolved(): bool
    {
        return $this->status === self::STATUS_RESOLVED;
    }

    /** Waiting time in seconds, or null if not waiting for an admin. */
    public function waitingSeconds(): ?int
    {
        return $this->waiting_since ? max(0, (int) $this->waiting_since->diffInSeconds(now(), true)) : null;
    }
}
