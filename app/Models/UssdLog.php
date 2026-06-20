<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UssdLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'request_data',
        'response_data',
    ];

    protected $casts = [
        'request_data' => 'array',
    ];
}
