<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorResultCheckerSetting extends Model
{
    protected $table = 'vendor_result_checker_settings';

    protected $fillable = [
        'vendor_id',
        'service_id',
        'profit_amount',
        'is_active',
    ];

    protected $casts = [
        'profit_amount' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(NetworkService::class, 'service_id');
    }
}
