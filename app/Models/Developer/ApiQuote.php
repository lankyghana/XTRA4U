<?php

namespace App\Models\Developer;

use Illuminate\Database\Eloquent\Model;

class ApiQuote extends Model
{
    protected $table = 'api_quotes';

    protected $guarded = [];

    protected $casts = [
        'snapshot' => 'array',
        'amount' => 'decimal:2',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
