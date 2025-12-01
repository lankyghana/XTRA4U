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
        'is_active',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }
}
