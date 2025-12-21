<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vendor extends Authenticatable
{
    use HasFactory;

    // Vendor has many orders
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    // Vendor has many products
    public function products()
    {
        return $this->hasMany(Product::class);
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
        'vendor_code',
        'balance',
        'wallet_balance',
        'pending_balance',
        'afa_price',
        'afa_enabled',
        'afa_reseller_enabled',
        'afa_source_vendor_id',
        'afa_base_price',
        'afa_markup',
        'afa_selling_price',
    ];

    protected $casts = [
        'is_approved' => 'boolean',
        'afa_enabled' => 'boolean',
        'afa_reseller_enabled' => 'boolean',
        'balance' => 'decimal:2',
        'wallet_balance' => 'decimal:2',
        'pending_balance' => 'decimal:2',
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
