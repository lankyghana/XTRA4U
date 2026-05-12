<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NetworkService extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'category',
        'service_type',
        'base_price',
        'description',
        'is_active',
        'image_path',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'base_price' => 'decimal:2',
    ];

    /**
     * Scope a query to only include active services.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include results checker services.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeResultsChecker($query)
    {
        return $query->where('service_type', 'results_checker');
    }

    /**
     * Scope a query to only include general services.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeGeneral($query)
    {
        return $query->where('service_type', 'general');
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? asset('storage/' . $this->image_path) : null;
    }

    // Relationships for result checkers
    public function pins()
    {
        return $this->hasMany(ResultCheckerPin::class, 'service_id');
    }

    public function availablePins()
    {
        return $this->pins()->where('status', 'available');
    }

    public function orders()
    {
        return $this->hasMany(ResultCheckerOrder::class, 'service_id');
    }

    public function vendorSettings()
    {
        return $this->hasMany(VendorResultCheckerSetting::class, 'service_id');
    }

    public function pricingTiers()
    {
        return $this->hasMany(ResultCheckerPricingTier::class);
    }

    public function getAvailableStockCountAttribute(): int
    {
        return $this->availablePins()->count();
    }

    public function getPriceForQuantity(int $quantity): float
    {
        $tier = $this->pricingTiers()
            ->where('min_quantity', '<=', $quantity)
            ->where(function ($query) use ($quantity) {
                $query->where('max_quantity', '>=', $quantity)
                    ->orWhereNull('max_quantity');
            })
            ->orderBy('min_quantity', 'desc')
            ->first();

        if ($tier) {
            return (float) $tier->price;
        }

        return (float) $this->base_price;
    }
}
