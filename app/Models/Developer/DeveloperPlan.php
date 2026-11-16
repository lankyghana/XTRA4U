<?php

namespace App\Models\Developer;

use Illuminate\Database\Eloquent\Model;

/**
 * Admin-managed plan. Limits are data, never hardcoded; no prices are stored
 * here (billing is a separate, not-yet-wired concern).
 */
class DeveloperPlan extends Model
{
    protected $table = 'developer_plans';

    protected $fillable = [
        'code', 'name', 'requests_per_minute', 'monthly_request_quota',
        'max_applications', 'max_webhooks', 'is_default', 'is_active', 'meta',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];
}
