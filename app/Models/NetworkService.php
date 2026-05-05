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

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeResultsChecker($query)
    {
        return $query->where('service_type', 'results_checker');
    }

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
        return $this->hasMany(ResultCheckerPin::class, 'checker_type_id');
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

    public function getAvailableStockCountAttribute(): int
    {
        return $this->availablePins()->count();
    }
}
