<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminNotification extends Model
{
    protected $fillable = [
        'type',
        'title',
        'message',
        'data',
        'order_id',
        'vendor_id',
        'read_at',
    ];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
    ];

    // Notification types
    const TYPE_NEW_ORDER = 'new_order';
    const TYPE_ORDER_COMPLETED = 'order_completed';
    const TYPE_ORDER_CANCELLED = 'order_cancelled';
    const TYPE_NEW_VENDOR = 'new_vendor';
    const TYPE_VENDOR_APPROVED = 'vendor_approved';
    const TYPE_VENDOR_REJECTED = 'vendor_rejected';
    const TYPE_WITHDRAWAL_REQUEST = 'withdrawal_request';
    const TYPE_WITHDRAWAL_APPROVED = 'withdrawal_approved';
    const TYPE_WITHDRAWAL_REJECTED = 'withdrawal_rejected';
    const TYPE_NEW_PRODUCT = 'new_product';
    const TYPE_AFFILIATE_ORDER = 'affiliate_order';

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    public function scopeRecent($query, $limit = 20)
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
     * Create notification for new order
     */
    public static function notifyNewOrder(Order $order): void
    {
        $type = $order->is_reseller_order ? self::TYPE_AFFILIATE_ORDER : self::TYPE_NEW_ORDER;
        $title = $order->is_reseller_order ? 'New Affiliate Order' : 'New Order Placed';
        
        self::create([
            'type' => $type,
            'title' => $title,
            'message' => "Order #{$order->id} for {$order->service_purchased}. Amount: GHS " . number_format($order->amount_paid, 2),
            'order_id' => $order->id,
            'vendor_id' => $order->vendor_id,
            'data' => [
                'order_id' => $order->id,
                'amount' => $order->amount_paid,
                'product' => $order->service_purchased,
                'vendor_id' => $order->vendor_id,
                'is_reseller' => $order->is_reseller_order,
            ],
        ]);
    }

    /**
     * Create notification for new vendor registration
     */
    public static function notifyNewVendor(Vendor $vendor): void
    {
        self::create([
            'type' => self::TYPE_NEW_VENDOR,
            'title' => 'New Vendor Registration',
            'message' => "{$vendor->name} ({$vendor->business_name}) has registered and is pending approval.",
            'vendor_id' => $vendor->id,
            'data' => [
                'vendor_id' => $vendor->id,
                'vendor_name' => $vendor->name,
                'business_name' => $vendor->business_name,
                'email' => $vendor->email,
            ],
        ]);
    }

    /**
     * Create notification for withdrawal request
     */
    public static function notifyWithdrawalRequest($withdrawal): void
    {
        self::create([
            'type' => self::TYPE_WITHDRAWAL_REQUEST,
            'title' => 'New Withdrawal Request',
            'message' => "Vendor #{$withdrawal->vendor_id} requested withdrawal of GHS " . number_format($withdrawal->amount, 2),
            'vendor_id' => $withdrawal->vendor_id,
            'data' => [
                'withdrawal_id' => $withdrawal->id,
                'amount' => $withdrawal->amount,
                'vendor_id' => $withdrawal->vendor_id,
            ],
        ]);
    }
}
