<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ResultCheckerOrder extends Model
{
    use HasFactory;
    protected $table = 'result_checker_orders';

    protected $fillable = [
        'vendor_id',
        'service_id',
        'customer_phone',
        'customer_name',
        'quantity',
        'unit_price',
        'total_price',
        'vendor_profit',
        'delivered_pins_json',
        'status',
        'payment_reference',
        'payment_gateway',
        'paid_at',
        'fulfilled_at',
        'sms_sent_at',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
        'vendor_profit' => 'decimal:2',
        'delivered_pins_json' => 'encrypted:array',
        'paid_at' => 'datetime',
        'fulfilled_at' => 'datetime',
        'sms_sent_at' => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(NetworkService::class, 'service_id');
    }

    public function pins(): HasMany
    {
        return $this->hasMany(ResultCheckerPin::class, 'result_checker_order_id');
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['pending_payment', 'pending_stock']);
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null && $this->status !== 'failed';
    }

    public function isFulfilled(): bool
    {
        return $this->status === 'completed' && $this->fulfilled_at !== null;
    }

    public function scopeByPhone($query, string $phone)
    {
        return $query->where('customer_phone', $phone);
    }

    public function scopeByPaymentReference($query, string $reference)
    {
        return $query->where('payment_reference', $reference);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeForVendor($query, int $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }
}
