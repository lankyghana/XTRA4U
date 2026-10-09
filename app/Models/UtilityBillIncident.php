<?php

namespace App\Models;

use App\Services\UtilityBills\UtilityBillIncidents;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A provider-level operational incident (outage, key rejected, wallet low, ...)
 * grouping the Utility Bill orders it affected. Written only by
 * UtilityBillIncidents; nothing is mass-assignable.
 */
class UtilityBillIncident extends Model
{
    protected $fillable = [];

    protected $casts = [
        'first_detected_at' => 'datetime',
        'last_detected_at' => 'datetime',
        'last_alerted_at' => 'datetime',
        'recovered_at' => 'datetime',
    ];

    public const STATE_ACTIVE = 'active';

    public const STATE_RECOVERED = 'recovered';

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(UtilityBillOrder::class, 'utility_bill_incident_orders')->withPivot('first_seen_at');
    }

    public function isActive(): bool
    {
        return $this->state === self::STATE_ACTIVE;
    }

    public function label(): string
    {
        return UtilityBillIncidents::label($this->category).($this->scope !== '' ? ' ('.$this->scope.')' : '');
    }
}
