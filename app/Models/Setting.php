<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'group'];

    /**
     * Get a setting value by key
     */
    public static function get(string $key, $default = null)
    {
        $setting = Cache::remember("setting.{$key}", 3600, function () use ($key) {
            return self::where('key', $key)->first();
        });

        return $setting ? $setting->value : $default;
    }

    /**
     * Get a setting value straight from the `settings` table: same result as get() (the row's value, or $default when
     * there is no row), but it never reads or writes the cache.
     *
     * Use this where a cache WRITE is not allowed - notably inside an AI tool, whose database access is guarded to plain
     * SELECTs. get() is a read-through cache: on the database cache store a miss issues `INSERT INTO cache ...`, and a
     * setting that has no row at all does so on EVERY call (the null result cannot be told apart from a miss), so it
     * cannot run inside that guard. This also means the value is never stale: it reflects the table, whereas get() may
     * answer from the cache for up to an hour after a direct edit of the table (set() always invalidates it).
     */
    public static function getFresh(string $key, $default = null)
    {
        $setting = self::where('key', $key)->first(['value']);

        return $setting ? $setting->value : $default;
    }

    /**
     * Set a setting value
     */
    public static function set(string $key, $value, string $group = 'general'): void
    {
        self::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'group' => $group]
        );

        Cache::forget("setting.{$key}");
        Cache::forget("settings.{$group}");
    }

    /**
     * Get all settings for a group
     */
    public static function getGroup(string $group): array
    {
        return Cache::remember("settings.{$group}", 3600, function () use ($group) {
            return self::where('group', $group)
                ->pluck('value', 'key')
                ->toArray();
        });
    }

    /**
     * Get all settings for a group without cache.
     *
     * This is useful for long-running processes (queue workers) where stale
     * cached settings can cause emails to be sent with old SMTP credentials.
     */
    public static function getGroupFresh(string $group): array
    {
        return self::where('group', $group)
            ->pluck('value', 'key')
            ->toArray();
    }

    /**
     * Clear all settings cache
     */
    public static function clearCache(): void
    {
        $keys = self::pluck('key');
        foreach ($keys as $key) {
            Cache::forget("setting.{$key}");
        }

        $groups = self::distinct()->pluck('group');
        foreach ($groups as $group) {
            Cache::forget("settings.{$group}");
        }
    }
}
