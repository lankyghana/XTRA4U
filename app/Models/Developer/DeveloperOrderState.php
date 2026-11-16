<?php

namespace App\Models\Developer;

use Illuminate\Database\Eloquent\Model;

class DeveloperOrderState extends Model
{
    public $timestamps = false;

    protected $table = 'developer_order_states';

    protected $guarded = [];

    protected $casts = ['is_terminal' => 'boolean', 'last_seen_at' => 'datetime'];
}
