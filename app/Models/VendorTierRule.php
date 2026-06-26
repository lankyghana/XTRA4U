<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorTierRule extends Model
{
    protected $fillable = [
        'tier_id',
        'rule_key',
        'operator',
        'value',
    ];

    protected $casts = [
        'value' => 'decimal:2',
    ];

    public function tier(): BelongsTo
    {
        return $this->belongsTo(VendorTier::class, 'tier_id');
    }

    public static function supportedRuleKeys(): array
    {
        return [
            'min_affiliate_sales'     => 'Minimum Affiliate Sales (GHS)',
            'min_affiliate_orders'    => 'Minimum Affiliate Orders',
            'min_successful_orders'   => 'Minimum Successful Orders',
            'min_success_rate'        => 'Minimum Success Rate (%)',
            'min_account_age_days'    => 'Minimum Account Age (Days)',
            'max_refund_rate'         => 'Maximum Refund Rate (%)',
            'max_complaint_rate'      => 'Maximum Complaint Rate (%)',
            'min_wallet_volume'       => 'Minimum Wallet Volume (GHS)',
        ];
    }

    public static function supportedOperators(): array
    {
        return ['>=', '<=', '>', '<', '='];
    }
}
