<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportMessage extends Model
{
    public const SENDER_VENDOR = 'vendor';

    public const SENDER_ADMIN = 'admin';

    // Sender identity is server-derived; nothing is mass assignable.
    protected $guarded = ['*'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SupportConversation::class, 'conversation_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SupportAttachment::class, 'message_id');
    }

    public function isFromVendor(): bool
    {
        return $this->sender_type === self::SENDER_VENDOR;
    }
}
