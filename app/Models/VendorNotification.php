<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorNotification extends Model
{
    protected $fillable = [
        'vendor_id',
        'type',
        'title',
        'message',
        'data',
        'read_at',
        'order_id',
    ];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
    ];

    // Notification types
    const TYPE_NEW_ORDER = 'new_order';
    const TYPE_AFFILIATE_ORDER = 'affiliate_order';
    const TYPE_ORDER_COMPLETED = 'order_completed';
    const TYPE_WITHDRAWAL_APPROVED = 'withdrawal_approved';
    const TYPE_WITHDRAWAL_REJECTED = 'withdrawal_rejected';
    const TYPE_AFA_REGISTRATION = 'afa_registration';

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    public function scopeRecent($query, $limit = 10)
    {
        return $query->latest()->limit($limit);
    }

    public function markAsRead(): void
    {
        if (!$this->read_at) {
            $this->update(['read_at' => now()]);
        }
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }

    /**
     * Create a new order notification for a vendor
     */
    public static function notifyNewOrder(Order $order): void
    {
        // Notify the primary vendor (reseller for affiliate orders, owner for regular)
        self::create([
            'vendor_id' => $order->vendor_id,
            'type' => $order->is_reseller_order ? self::TYPE_AFFILIATE_ORDER : self::TYPE_NEW_ORDER,
            'title' => 'New Order Received!',
            'message' => "You received a new order for {$order->service_purchased}. Amount: GHS " . number_format($order->amount_paid, 2),
            'data' => [
                'order_id' => $order->id,
                'amount' => $order->amount_paid,
                'product' => $order->service_purchased,
                'recipient' => $order->recipient_phone_number,
            ],
        ]);

        // For affiliate orders, also notify the product owner
        if ($order->is_reseller_order && $order->owner_vendor_id) {
            self::create([
                'vendor_id' => $order->owner_vendor_id,
                'type' => self::TYPE_AFFILIATE_ORDER,
                'title' => 'New Affiliate Order!',
                'message' => "Your product {$order->service_purchased} was sold by a reseller. Your earning: GHS " . number_format($order->owner_earning, 2),
                'data' => [
                    'order_id' => $order->id,
                    'amount' => $order->base_price,
                    'earning' => $order->owner_earning,
                    'product' => $order->service_purchased,
                    'recipient' => $order->recipient_phone_number,
                ],
            ]);
        }
    }
}
