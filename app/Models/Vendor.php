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
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'is_approved' => 'boolean',
    ];
}
