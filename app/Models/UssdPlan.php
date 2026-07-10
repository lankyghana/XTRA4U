<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class UssdPlan extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'price',
        'included_sessions',
        'duration_days',
        'extension_code',
        'is_active',
        'display_order',
        'notes',
    ];

    protected $casts = [
        'price' => 'float',
        'included_sessions' => 'integer',
        'duration_days' => 'integer',
        'is_active' => 'boolean',
        'display_order' => 'integer',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(UssdSubscription::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('display_order')->orderBy('price');
    }

    /**
     * A plan may only be purchased while active. Enforced again inside
     * UssdSubscriptionPurchaseService so an inactive plan cannot be bought by
     * posting its id directly.
     */
    public function isPurchasable(): bool
    {
        return $this->is_active && ! $this->trashed();
    }

    /**
     * A plan with live subscriptions must never be hard-deleted, otherwise the
     * restrictOnDelete foreign key throws at the database layer.
     */
    public function hasLiveSubscriptions(): bool
    {
        return $this->subscriptions()
            ->whereIn('status', UssdSubscription::LIVE_STATUSES)
            ->exists();
    }
}
