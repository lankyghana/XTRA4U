<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResultCheckerPin extends Model
{
    protected $table = 'result_checker_pins';

    protected $fillable = [
        'service_id',
        'pin',
        'serial',
        'status',
        'result_checker_order_id',
        'sold_at',
    ];

    protected $casts = [
        'sold_at' => 'datetime',
    ];

    protected $hidden = ['pin', 'serial']; // Never expose in API responses by default

    public function service(): BelongsTo
    {
        return $this->belongsTo(NetworkService::class, 'service_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ResultCheckerOrder::class, 'result_checker_order_id');
    }

    public function isAvailable(): bool
    {
        return $this->status === 'available';
    }

    public function isSold(): bool
    {
        return $this->status === 'sold';
    }

    public function isReserved(): bool
    {
        return $this->status === 'reserved';
    }

    public function scopeAvailable($query)
    {
        return $query->where('status', 'available');
    }

    public function scopeForService($query, int $serviceId)
    {
        return $query->where('service_id', $serviceId);
    }

    public function scopeSold($query)
    {
        return $query->where('status', 'sold');
    }
}
