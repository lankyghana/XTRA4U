<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class GigshubLowBalanceAlert extends Model
{
    protected $table = 'gigshub_low_balance_alerts';

    protected $fillable = [
        'balance',
        'threshold',
        'currency',
        'message',
        'acknowledged_at',
        'acknowledged_by',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'threshold' => 'decimal:2',
        'acknowledged_at' => 'datetime',
    ];

    public function scopeUnacknowledged(Builder $query): Builder
    {
        return $query->whereNull('acknowledged_at');
    }

    public function scopeRecent(Builder $query, $minutes = 60): Builder
    {
        return $query->where('created_at', '>=', now()->subMinutes($minutes));
    }

    public function acknowledge($adminId = null)
    {
        $this->update([
            'acknowledged_at' => now(),
            'acknowledged_by' => $adminId,
        ]);
    }
}
