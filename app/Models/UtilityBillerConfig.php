<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Admin-owned per-biller switch and vendor commission terms. */
class UtilityBillerConfig extends Model
{
    protected $fillable = ['biller_key', 'is_enabled', 'commission_type', 'commission_value', 'updated_by_admin_id'];

    protected $casts = [
        'is_enabled' => 'boolean',
        'commission_value' => 'decimal:4',
    ];
}
