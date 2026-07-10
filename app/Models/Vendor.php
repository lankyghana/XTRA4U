<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Vendor extends Authenticatable
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Vendor $vendor): void {
            // Business rule: A vendor cannot be an Affiliate without also being an AFA Affiliate.
            // Enforce idempotently at the model layer so it applies across all entry points.
            if (!is_null($vendor->affiliate_vendor_id) && $vendor->is_afa_affiliate !== true) {
                $vendor->is_afa_affiliate = true;
            }
        });
    }

    // Vendor has many orders
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    // Vendor has many products
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function affiliateVendor()
    {
        return $this->belongsTo(Vendor::class, 'affiliate_vendor_id');
    }

    public function affiliates(): HasMany
    {
        return $this->hasMany(Vendor::class, 'affiliate_vendor_id');
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(VendorWithdrawal::class);
    }

    public function ussdSubscriptions(): HasMany
    {
        return $this->hasMany(UssdSubscription::class);
    }

    /**
     * The vendor's single live subscription, if any. Resolved through
     * current_for_vendor_id, whose unique index guarantees at most one row.
     */
    public function currentUssdSubscription(): HasOne
    {
        return $this->hasOne(UssdSubscription::class, 'current_for_vendor_id');
    }

    public function hasUssdAccess(): bool
    {
        return (bool) $this->currentUssdSubscription?->isUsable();
    }

    protected $fillable = [
        'name',
        'email',
        'phone_number',
        'password',
        'is_approved',
        'affiliate_vendor_id',
        'vendor_code',
        'balance',
        'wallet_balance',
        'afa_price',
        'afa_enabled',
        'afa_reseller_enabled',
        'afa_source_vendor_id',
        'afa_base_price',
        'afa_markup',
        'afa_selling_price',
        'is_afa_affiliate',
        'tier_id',
        'eligible_for_tier_id',
        'is_tier_eligible',
        'tier_qualified_at',
        'tier_reviewed_at',
        'tier_reviewed_by',
    ];

    protected $casts = [
        'is_approved' => 'boolean',
        'is_afa_affiliate' => 'boolean',
        'afa_enabled' => 'boolean',
        'afa_reseller_enabled' => 'boolean',
        'balance' => 'decimal:2',
        'wallet_balance' => 'decimal:2',
        'afa_price' => 'decimal:2',
        'afa_base_price' => 'decimal:2',
        'afa_markup' => 'decimal:2',
        'afa_selling_price' => 'decimal:2',
        'is_tier_eligible' => 'boolean',
        'tier_qualified_at' => 'datetime',
        'tier_reviewed_at' => 'datetime',
    ];

    /**
     * Get the source vendor for AFA reselling
     */
    public function afaSourceVendor()
    {
        return $this->belongsTo(Vendor::class, 'afa_source_vendor_id');
    }

    /**
     * Get AFA registrations for this vendor
     */
    public function afaRegistrations(): HasMany
    {
        return $this->hasMany(AfaRegistration::class);
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(VendorTier::class, 'tier_id');
    }

    public function eligibleTier(): BelongsTo
    {
        return $this->belongsTo(VendorTier::class, 'eligible_for_tier_id');
    }

    public function tierHistories(): HasMany
    {
        return $this->hasMany(VendorTierHistory::class);
    }

    /**
     * The discount rate this vendor's tier grants on base buying price (0.0 – 1.0).
     * Returns 0.0 if no tier is assigned or if the tier has no discount.
     */
    public function getEffectiveTierDiscountRate(): float
    {
        return $this->tier ? $this->tier->getDiscountRate() : 0.0;
    }

    /**
     * Apply the vendor's tier discount to a base amount.
     * Returns the effective amount (reduced by discount).
     */
    public function applyTierDiscount(float $baseAmount): float
    {
        $rate = $this->getEffectiveTierDiscountRate();
        if ($rate <= 0.0) {
            return $baseAmount;
        }

        return round($baseAmount * (1 - $rate), 2);
    }

    protected $hidden = [
        'password',
    ];

    public static function generateUniqueCode(string $name): string
    {
        $namePrefix = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $name), 0, 4));
        $namePrefix = $namePrefix !== '' ? $namePrefix : 'VEND';

        do {
            $randomSuffix = strtoupper(Str::random(6));
            $vendorCode = $namePrefix . $randomSuffix;
            $exists = self::where('vendor_code', $vendorCode)->exists();
        } while ($exists);

        return $vendorCode;
    }

    public function ensureVendorCode(): void
    {
        if ($this->vendor_code) {
            return;
        }

        $this->vendor_code = self::generateUniqueCode($this->name ?? 'Vendor');
        $this->save();
    }
}
