<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Transaction extends Model
{
    protected $fillable = [
        'order_id',
        'vendor_id',
        'recipient_phone',
        'amount',
        'commission_amount',
        'vendor_earning',
        'payment_status',
        'gateway_transaction_id',
        'timestamp',
        'transactionable_type',
        'transactionable_id',
        'payment_type',
    ];

    /**
     * Polymorphic relationship: Transaction can belong to Order or AfaRegistration
     */
    public function transactionable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Legacy relationship for backward compatibility
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * Scope to get transactions for a specific payment type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('payment_type', $type);
    }

    /**
     * Scope to get order transactions
     */
    public function scopeOrders($query)
    {
        return $query->where('payment_type', 'order');
    }

    /**
     * Scope to get AFA registration transactions
     */
    public function scopeAfaRegistrations($query)
    {
        return $query->where('payment_type', 'afa_registration');
    }
}
