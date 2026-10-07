<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Who changed which Utility Bills setting, and from what to what. */
class UtilityBillConfigAudit extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['admin_id', 'admin_email', 'scope', 'biller_key', 'old_values', 'new_values', 'ip_address'];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];
}
