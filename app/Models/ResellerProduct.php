<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResellerProduct extends Model
{
    protected $fillable = [
        'product_id',
        'reseller_vendor_id',
        'owner_vendor_id',
        'base_price',
        'markup_price',
        'selling_price',
        'is_active',
    ];

    protected $casts = [
        'base_price' => 'decimal:2',
        'markup_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    // Original product
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // Vendor who is reselling
    public function resellerVendor()
    {
        return $this->belongsTo(Vendor::class, 'reseller_vendor_id');
    }

    // Original product owner
    public function ownerVendor()
    {
        return $this->belongsTo(Vendor::class, 'owner_vendor_id');
    }

    // Calculate selling price automatically
    public static function boot()
    {
        parent::boot();

        static::saving(function ($resellerProduct) {
            $resellerProduct->selling_price = $resellerProduct->base_price + $resellerProduct->markup_price;
        });
    }
}
