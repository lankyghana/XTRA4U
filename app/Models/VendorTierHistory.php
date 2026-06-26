<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorTierHistory extends Model
{
    protected $fillable = [
        'vendor_id',
        'previous_tier_id',
        'new_tier_id',
        'action',
        'admin_id',
        'notes',
    ];

    protected $casts = [];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function previousTier(): BelongsTo
    {
        return $this->belongsTo(VendorTier::class, 'previous_tier_id');
    }

    public function newTier(): BelongsTo
    {
        return $this->belongsTo(VendorTier::class, 'new_tier_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }

    public static function actions(): array
    {
        return [
            'assigned' => 'Assigned',
            'promoted' => 'Promoted',
            'rejected' => 'Rejected',
            'demoted'  => 'Demoted',
            'reset'    => 'Reset',
        ];
    }

    public function getActionLabelAttribute(): string
    {
        return self::actions()[$this->action] ?? ucfirst($this->action);
    }
}
