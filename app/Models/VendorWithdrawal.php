<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorWithdrawal extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const NETWORK_MTN = 'MTN';
    public const NETWORK_TELECEL = 'TELECEL';
    public const NETWORK_AIRTELTIGO = 'AirtelTigo';

    protected $fillable = [
        'vendor_id',
        'amount',
        'momo_number',
        'momo_network',
        'status',
        'reference',
        'payout_reference',
        'payout_transaction_id',
        'payout_status',
        'paid_at',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
        ];
    }

    public static function networks(): array
    {
        return [
            self::NETWORK_MTN,
            self::NETWORK_TELECEL,
            self::NETWORK_AIRTELTIGO,
        ];
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
