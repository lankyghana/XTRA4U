<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorWithdrawal extends Model
{
    use HasFactory;

    public const STATUS_PROCESSING = 'processing';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const NETWORK_MTN = 'MTN';
    public const NETWORK_TELECEL = 'TELECEL';
    public const NETWORK_AIRTELTIGO = 'AirtelTigo';

    protected $fillable = [
        'vendor_id',
        'amount',
        'momo_number',
        'momo_network',
        'momo_account_name',
        'momo_account_type',
        'payout_gateway',
        'status',
        'reference',
        'payout_reference',
        'payout_transaction_id',
        'payout_status',
        'paid_at',
        'payout_attempted_at',
        'refunded_at',
        'error_message',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'payout_attempted_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    public static function statuses(): array
    {
        return [
            self::STATUS_PROCESSING,
            self::STATUS_APPROVED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
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

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
