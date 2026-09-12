<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WalletTopup extends Model
{
    use HasFactory;

    protected $table = 'wallet_topups';

    protected $fillable = [
        'reference', 'vendor_id', 'amount', 'status', 'payment_gateway', 'metadata', 'gateway_response', 'consumed',
        'reconciliation_attempts', 'last_reconciliation_at', 'next_reconciliation_at', 'reconciliation_note',
        'idempotency_scope', 'idempotency_key',
    ];

    protected $casts = [
        'metadata' => 'array',
        'gateway_response' => 'array',
        'amount' => 'float',
        'consumed' => 'float',
        'last_reconciliation_at' => 'datetime',
        'next_reconciliation_at' => 'datetime',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }
}
