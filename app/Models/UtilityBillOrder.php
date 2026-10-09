<?php

namespace App\Models;

use App\Services\UtilityBills\FulfillmentStatus;
use App\Support\PaymentIntegrity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One Utility Bill sale. 1:1 with the `orders` row that carries the customer
 * payment and its integrity proof. Every financial/attribution field here is
 * frozen at creation and written only by the UtilityBills services (never mass
 * assigned, never from request input).
 */
class UtilityBillOrder extends Model
{
    /** Nothing is mass-assignable: services write with forceFill. */
    protected $fillable = [];

    protected $hidden = ['access_token', 'claim_token', 'lookup_snapshot'];

    protected $casts = [
        'lookup_snapshot' => 'array',
        'amount_due_at_lookup' => 'decimal:2',
        'bill_amount' => 'decimal:2',
        'expected_amount' => 'decimal:2',
        'provider_commission_share_percent' => 'decimal:2',
        'provider_commission_earned' => 'decimal:2',
        'commission_value' => 'decimal:4',
        'commission_basis_amount' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'claimed_at' => 'datetime',
        'next_submit_at' => 'datetime',
        'submitted_at' => 'datetime',
        'last_status_check_at' => 'datetime',
        'next_status_check_at' => 'datetime',
        'fulfilled_at' => 'datetime',
        'customer_notified_at' => 'datetime',
        'terminal_alerted_at' => 'datetime',
        'stuck_alerted_at' => 'datetime',
        'commission_credited_at' => 'datetime',
    ];

    public const COMMISSION_NONE = 'none';

    public const COMMISSION_PENDING = 'pending';

    public const COMMISSION_CREDITED = 'credited';

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(UtilityBillEvent::class)->orderBy('id');
    }

    /** Sales whose customer payment is proven (excludes abandoned/unpaid checkouts). */
    public function scopePaidSales($query)
    {
        return $query->whereHas('order', fn ($o) => $o
            ->whereIn('payment_status', ['paid', 'completed'])
            ->whereIn('payment_integrity_status', PaymentIntegrity::SETTLEMENT_ALLOWED));
    }

    /** Vendor-facing status: sales value and earnings stay unambiguous. */
    public function vendorStatusLabel(): string
    {
        return match (true) {
            $this->fulfillment_status === FulfillmentStatus::COMPLETED => 'Completed',
            in_array($this->fulfillment_status, [FulfillmentStatus::FAILED, FulfillmentStatus::PROVIDER_REFUNDED], true) => 'Failed',
            default => 'Processing',
        };
    }

    public static function newPublicRef(): string
    {
        return 'UB'.strtoupper(Str::random(10));
    }

    public static function newAccessToken(): string
    {
        return Str::random(40);
    }

    /** Payment proof/lifecycle lives on the envelope order, never duplicated here. */
    public function isPaid(): bool
    {
        $order = $this->relationLoaded('order') ? $this->order : $this->order()->first();

        return $order !== null
            && in_array($order->payment_status, ['paid', 'completed'], true)
            && $order->allowsSettlement();
    }

    /**
     * Customer-facing "payment received": the envelope order holds trusted payment proof AND
     * settlement happened (paid status, completion time, or fulfillment already moved past
     * awaiting_payment, which only PaymentService does after proof). Read from persisted state,
     * so a late or contradictory browser verification can never make a received payment look failed.
     */
    public function paymentReceived(): bool
    {
        $order = $this->relationLoaded('order') ? $this->order : $this->order()->first();

        if ($order === null || ! $order->allowsSettlement()) {
            return false;
        }

        return in_array($order->payment_status, ['paid', 'completed'], true)
            || $order->payment_completed_at !== null
            || $this->fulfillment_status !== FulfillmentStatus::AWAITING_PAYMENT;
    }

    public function isCompleted(): bool
    {
        return $this->fulfillment_status === FulfillmentStatus::COMPLETED;
    }

    public function isTerminal(): bool
    {
        return FulfillmentStatus::isTerminal($this->fulfillment_status);
    }

    /** "••••1234": enough to recognise, never enough to misuse. */
    public static function mask(?string $value): string
    {
        $value = (string) $value;
        $tail = substr($value, -4);

        return $value === '' ? '' : '••••'.$tail;
    }

    public function maskedAccount(): string
    {
        return self::mask($this->account_number);
    }

    public function maskedPhone(): string
    {
        return self::mask($this->customer_phone);
    }

    public function statusUrl(): string
    {
        return route('utility-bills.status', ['token' => $this->access_token]);
    }
}
