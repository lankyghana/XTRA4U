<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminExportAudit extends Model
{
    protected $guarded = [];

    protected $casts = [
        'filters' => 'array',
        'fields' => 'array',
        'completed' => 'boolean',
    ];
}
