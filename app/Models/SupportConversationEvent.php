<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only audit trail for a support conversation. */
class SupportConversationEvent extends Model
{
    public const CREATED = 'created';

    public const REOPENED = 'reopened';

    public const RESOLVED = 'resolved';

    public const CLOSED = 'closed';

    public const STATUS_CHANGED = 'status_changed';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];
}
