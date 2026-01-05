<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class VendorSetting extends Model
{
    protected $fillable = ['vendor_id', 'key', 'value', 'group'];

    /**
     * Get a vendor setting value by key.
     */
    public static function getForVendor(int $vendorId, string $key, $default = null)
    {
        $cacheKey = sprintf('vendor_setting.%d.%s', $vendorId, $key);

        $setting = Cache::remember($cacheKey, 3600, function () use ($vendorId, $key) {
            return self::where('vendor_id', $vendorId)->where('key', $key)->first();
        });

        return $setting ? $setting->value : $default;
    }

    /**
     * Set a vendor setting value.
     */
    public static function setForVendor(int $vendorId, string $key, $value, string $group = 'general'): void
    {
        self::updateOrCreate(
            ['vendor_id' => $vendorId, 'key' => $key],
            ['value' => $value, 'group' => $group]
        );

        Cache::forget(sprintf('vendor_setting.%d.%s', $vendorId, $key));
        Cache::forget(sprintf('vendor_settings.%d.%s', $vendorId, $group));
    }

    /**
     * Get all vendor settings for a group without cache.
     */
    public static function getGroupFreshForVendor(int $vendorId, string $group): array
    {
        return self::where('vendor_id', $vendorId)
            ->where('group', $group)
            ->pluck('value', 'key')
            ->toArray();
    }

    /**
     * Get all vendor settings for a group.
     */
    public static function getGroupForVendor(int $vendorId, string $group): array
    {
        $cacheKey = sprintf('vendor_settings.%d.%s', $vendorId, $group);

        return Cache::remember($cacheKey, 3600, function () use ($vendorId, $group) {
            return self::getGroupFreshForVendor($vendorId, $group);
        });
    }
}
