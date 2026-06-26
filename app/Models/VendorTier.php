<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorTier extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'priority',
        'discount_type',
        'discount_value',
        'is_active',
    ];

    protected $casts = [
        'priority' => 'integer',
        'discount_value' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function rules(): HasMany
    {
        return $this->hasMany(VendorTierRule::class, 'tier_id');
    }

    public function vendors(): HasMany
    {
        return $this->hasMany(Vendor::class, 'tier_id');
    }

    public function eligibleVendors(): HasMany
    {
        return $this->hasMany(Vendor::class, 'eligible_for_tier_id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(VendorTierHistory::class, 'new_tier_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('priority');
    }

    /**
     * Returns the discount as a decimal fraction (e.g. 5% → 0.05).
     * Only percentage discounts are used in the pricing calculation.
     */
    public function getDiscountRate(): float
    {
        if ($this->discount_type !== 'percentage') {
            return 0.0;
        }

        return round((float) $this->discount_value / 100, 4);
    }

    /**
     * The Regular tier (priority 0) requires no qualification.
     */
    public function isBaseTier(): bool
    {
        return (int) $this->priority === 0;
    }
}
