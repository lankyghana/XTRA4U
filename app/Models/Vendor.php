<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    protected $hidden = [
        'password',
    ];
}
