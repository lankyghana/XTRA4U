<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WalletTopup extends Model
{
    use HasFactory;

    protected $table = 'wallet_topups';

    protected $fillable = [
        'reference', 'vendor_id', 'amount', 'status', 'metadata', 'gateway_response'
    ];

    protected $casts = [
        'metadata' => 'array',
        'gateway_response' => 'array',
        'amount' => 'float',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }
}
