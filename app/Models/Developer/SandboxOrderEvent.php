<?php

namespace App\Models\Developer;

use Illuminate\Database\Eloquent\Model;

/** Append-only status history of a sandbox order (production keeps none; the sandbox does). */
class SandboxOrderEvent extends Model
{
    public $timestamps = false;

    protected $table = 'developer_sandbox_order_events';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];
}
