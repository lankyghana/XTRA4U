<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletLedger extends Model
{
    protected $table = 'wallet_ledgers';

    protected $fillable = [
        'vendor_id', 'type', 'amount', 'balance_after', 'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'amount' => 'float',
        'balance_after' => 'float',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }
}
