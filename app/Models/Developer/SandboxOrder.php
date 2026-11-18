<?php

namespace App\Models\Developer;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A SIMULATED order. Lives only in developer_sandbox_orders; it is not an `orders` row and has no
 * relationship to transactions, wallets, commissions, inventory or fulfilment providers.
 *
 * Status values mirror the public API vocabulary (OrderResource) but are computed and stored here,
 * independently of production:
 *   payment_status     unpaid | pending | paid | failed
 *   fulfillment_status not_started | pending | processing | completed | failed
 *   status             awaiting_payment | payment_pending | processing | completed | failed
 */
class SandboxOrder extends Model
{
    protected $table = 'developer_sandbox_orders';

    protected $guarded = [];

    protected $casts = [
        'product_snapshot' => 'array',
        'fulfillment_result' => 'array',
        'unit_price' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'quantity' => 'integer',
    ];

    public function payment(): HasOne
    {
        return $this->hasOne(SandboxPayment::class, 'sandbox_order_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(SandboxOrderEvent::class, 'sandbox_order_id');
    }

    /** The overall status, derived from payment + fulfilment exactly like the production API does. */
    public function computeStatus(): string
    {
        return match (true) {
            $this->payment_status === 'failed', $this->fulfillment_status === 'failed' => 'failed',
            $this->fulfillment_status === 'completed' => 'completed',
            $this->payment_status === 'paid' => 'processing',
            $this->payment_status === 'pending' => 'payment_pending',
            default => 'awaiting_payment',
        };
    }
}
