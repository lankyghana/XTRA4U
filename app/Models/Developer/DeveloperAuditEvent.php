<?php

namespace App\Models\Developer;

use Illuminate\Database\Eloquent\Model;

class DeveloperAuditEvent extends Model
{
    public $timestamps = false;

    protected $table = 'developer_audit_events';

    protected $guarded = [];

    protected $casts = ['meta' => 'array', 'created_at' => 'datetime'];
}
