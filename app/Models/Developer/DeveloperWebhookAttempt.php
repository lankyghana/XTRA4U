<?php

namespace App\Models\Developer;

use Illuminate\Database\Eloquent\Model;

class DeveloperWebhookAttempt extends Model
{
    public $timestamps = false;

    protected $table = 'developer_webhook_attempts';

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime'];
}
