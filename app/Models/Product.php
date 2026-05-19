<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'vendor_id',
        'name',
        'description',
        'price',
        'image_path',
        'is_active',
        'is_resellable',
        'min_base_price',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_resellable' => 'boolean',
        'price' => 'decimal:2',
        'min_base_price' => 'decimal:2',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    // Reseller products (vendors reselling this product)
    public function resellerProducts()
    {
        return $this->hasMany(ResellerProduct::class);
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? asset('storage/' . $this->image_path) : null;
    }

    public function getDecodedDescriptionAttribute(): array
    {
        $value = $this->description;
        if (! $value) {
            return [];
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded)
            ? $decoded
            : [];
    }
}
