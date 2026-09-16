<?php

namespace App\Models;

use App\Support\PaymentIntegrity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Order extends Model
{
    protected $fillable = [
        'recipient_phone_number',
        'mobile_money_number',
        'mobile_money_network',
        'service_purchased',
        'amount_paid',
        // Immutable financial snapshot, frozen at order creation. Never
        // recalculated — a later product price or reseller markup change must
        // not alter what an existing order owes.
        'expected_amount',
        'currency',
        'pricing_snapshot_at',
        'vendor_id',
        'vendor_service_id',
        'status',
        'payment_status',
        'payment_source',
        'payment_reference',
        'payment_gateway',
        'payment_completed_at',
        'wallet_reversed_at',
        'duplicate_credit_reversed_at',
        'downloaded_at',
        'reseller_product_id',
        'owner_vendor_id',
        'reseller_vendor_id',
        'base_price',
        'markup_price',
        'owner_earning',
        'reseller_earning',
        'platform_commission',
        'affiliate_chain_snapshot',
        'is_reseller_order',
        'external_fulfillment_provider_used',
        'reconciliation_attempts',
        'last_reconciliation_at',
        'next_reconciliation_at',
        'reconciliation_note',
        'idempotency_scope',
        'idempotency_key',
    ];

    /**
     * Proof-of-payment columns are deliberately NOT mass assignable. They may
     * only be written by PaymentIntegrityGuard (which uses forceFill), so no
     * controller — present or future — can mark an order's payment as proven
     * by passing a request field through to create()/update():
     *   payment_integrity_status, payment_integrity_note,
     *   gateway_confirmed_amount, gateway_confirmed_currency,
     *   gateway_transaction_id, payment_verified_at.
     */
    protected $casts = [
        'is_reseller_order' => 'boolean',
        'amount_paid' => 'decimal:2',
        'expected_amount' => 'decimal:2',
        'gateway_confirmed_amount' => 'decimal:2',
        'pricing_snapshot_at' => 'datetime',
        'payment_verified_at' => 'datetime',
        'payment_verified_aspects' => 'array',
        'base_price' => 'decimal:2',
        'markup_price' => 'decimal:2',
        'owner_earning' => 'decimal:2',
        'reseller_earning' => 'decimal:2',
        'platform_commission' => 'decimal:2',
        'affiliate_chain_snapshot' => 'array',
        'payment_completed_at' => 'datetime',
        'wallet_reversed_at' => 'datetime',
        'duplicate_credit_reversed_at' => 'datetime',
        'downloaded_at' => 'datetime',
        'external_fulfillment_attempts' => 'integer',
        'external_fulfillment_last_attempt_at' => 'datetime',
        'external_fulfillment_completed_at' => 'datetime',
        'external_fulfillment_delivered_at' => 'datetime',
        'external_fulfillment_last_status_check_at' => 'datetime',
        'last_reconciliation_at' => 'datetime',
        'next_reconciliation_at' => 'datetime',
    ];

    /**
     * Every order starts out with NO proof of payment, whatever created it.
     *
     * This is set here — not in each creation path — so that a path added in
     * future (a new controller, an importer, a console command, a test
     * fixture) cannot accidentally produce an order that is settleable
     * without a trusted source having proven it. Only PaymentIntegrityGuard
     * moves an order off this state.
     */
    protected static function booted(): void
    {
        static::creating(function (self $order) {
            if ($order->payment_integrity_status === null) {
                $order->payment_integrity_status = PaymentIntegrity::PENDING_VERIFICATION;
            }
        });
    }

    /**
     * True when this order carries the immutable pricing snapshot frozen at
     * creation. Orders created before that hardening return false, and must
     * never have a snapshot invented for them from current product prices.
     */
    public function hasPricingSnapshot(): bool
    {
        return $this->expected_amount !== null && $this->pricing_snapshot_at !== null;
    }

    /**
     * May financial side effects (transactions, wallet credits, earnings) be
     * created for this order? See {@see PaymentIntegrity}.
     */
    public function allowsSettlement(): bool
    {
        return PaymentIntegrity::allowsSettlement($this->payment_integrity_status);
    }

    /** May this order be submitted to an external fulfillment provider? */
    public function allowsFulfillment(): bool
    {
        return PaymentIntegrity::allowsFulfillment($this->payment_integrity_status);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * The product this order was for. Resolves soft-deleted products too: a
     * historical order must remain fully readable (and auditable back to the
     * exact service it bought) after its product has been withdrawn from sale.
     * The order's own frozen financial snapshot is what determines its
     * economics — this relation is for identification and display.
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'vendor_service_id')->withTrashed();
    }

    public function resellerProduct(): BelongsTo
    {
        return $this->belongsTo(ResellerProduct::class);
    }

    public function ownerVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'owner_vendor_id');
    }

    public function resellerVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'reseller_vendor_id');
    }

    /**
     * Legacy relationship: transactions linked via order_id
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Polymorphic relationship: transactions linked via transactionable
     */
    public function transactionRecords(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'transactionable');
    }

    public function getDisplayServiceNameAttribute(): string
    {
        $serviceName = $this->service?->name;
        if (is_string($serviceName) && $serviceName !== '') {
            return $serviceName;
        }

        $fallback = (string) $this->service_purchased;

        if ($fallback === '' || preg_match('/^[a-f0-9]{32}$/i', $fallback)) {
            return __('Unknown Service');
        }

        return $fallback;
    }

    public function getDisplayProductLabelAttribute(): string
    {
        $serviceName = $this->display_service_name;
        $packageSize = data_get($this->service, 'decoded_description.size');

        if (is_string($packageSize) && trim($packageSize) !== '') {
            return $serviceName.' - '.trim($packageSize);
        }

        return $serviceName;
    }
}
