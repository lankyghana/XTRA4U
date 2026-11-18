<?php

namespace App\Models\Developer;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A SIMULATED payment. No gateway, mobile-money network or wallet is ever involved. */
class SandboxPayment extends Model
{
    protected $table = 'developer_sandbox_payments';

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'simulated_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(SandboxOrder::class, 'sandbox_order_id');
    }
}
