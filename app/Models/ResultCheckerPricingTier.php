<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResultCheckerPricingTier extends Model
{
    use HasFactory;

    protected $fillable = [
        'network_service_id',
        'min_quantity',
        'max_quantity',
        'price',
    ];

    public function networkService()
    {
        return $this->belongsTo(NetworkService::class);
    }
}
