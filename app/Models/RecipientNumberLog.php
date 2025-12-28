<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipientNumberLog extends Model
{
    protected $fillable = [
        'phone_number',
        'vendor_id',
        'order_id',
        'service_type',
        'used_at',
    ];

    protected $casts = [
        'used_at' => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
