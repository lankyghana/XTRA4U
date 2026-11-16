<?php

namespace App\Models\Developer;

use Illuminate\Database\Eloquent\Model;

class DeveloperEvent extends Model
{
    public $timestamps = false;

    protected $table = 'developer_events';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
        'fanned_out_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
