<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only fulfillment/provider timeline entry. Never holds secrets or raw payloads. */
class UtilityBillEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['utility_bill_order_id', 'kind', 'from_status', 'to_status', 'http_status', 'detail', 'actor'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(UtilityBillOrder::class, 'utility_bill_order_id');
    }
}
