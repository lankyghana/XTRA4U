<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportAttachment extends Model
{
    public const KIND_IMAGE = 'image';

    public const KIND_AUDIO = 'audio';

    public $timestamps = false;

    // storage_path / mime_type are server-derived; never mass assignable.
    protected $guarded = ['*'];

    protected $casts = ['created_at' => 'datetime'];

    // The storage path is never exposed in JSON/array output.
    protected $hidden = ['storage_path', 'disk'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(SupportMessage::class, 'message_id');
    }
}
