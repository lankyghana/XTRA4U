<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'recipient_phone_number',
        'mobile_money_number',
        'service_purchased',
        'amount_paid',
        'vendor_id',
        'vendor_service_id',
        'status',
        'payment_status',
        'payment_reference',
        'payment_gateway',
        'payment_completed_at',
        'reseller_product_id',
        'owner_vendor_id',
        'reseller_vendor_id',
        'base_price',
        'markup_price',
        'owner_earning',
        'reseller_earning',
        'platform_commission',
        'is_reseller_order',
    ];

    protected $casts = [
        'is_reseller_order' => 'boolean',
        'amount_paid' => 'decimal:2',
        'base_price' => 'decimal:2',
        'markup_price' => 'decimal:2',
        'owner_earning' => 'decimal:2',
        'reseller_earning' => 'decimal:2',
        'platform_commission' => 'decimal:2',
        'payment_completed_at' => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'vendor_service_id');
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

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
