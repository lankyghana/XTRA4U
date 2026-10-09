<?php

namespace App\Services\UtilityBills;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Global, admin-owned Utility Bills switches (stored in the generic `settings`
 * table, like ServiceAvailability). Turning the service off stops NEW sales
 * only; existing orders keep fulfilling, syncing and recovering regardless.
 */
class UtilityBillSettings
{
    public const GROUP = 'utility_bills';

    public const KEY_ENABLED = 'utility_bills.enabled';

    public const KEY_MESSAGE = 'utility_bills.maintenance_message';

    public const KEY_IMAGE = 'utility_bills.image_path';

    public const KEY_WALLET_PAUSED = 'utility_bills.provider_wallet_paused_at';

    private const WALLET_ALERT_KEY = 'utility_bills.provider_wallet_alerted';

    /** Off until an admin deliberately enables it. */
    public static function enabled(): bool
    {
        // Fail closed: only an explicit stored '1' opens the service. A missing key, any other
        // value, or an unreadable settings/cache store all mean "off" (never an error that could
        // be mistaken for "on").
        try {
            return (string) Setting::get(self::KEY_ENABLED, '0') === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    public static function maintenanceMessage(): string
    {
        $message = trim((string) Setting::get(self::KEY_MESSAGE, ''));

        return $message !== '' ? $message : 'Utility bills are temporarily unavailable. Please try again later.';
    }

    public static function rawMaintenanceMessage(): string
    {
        return trim((string) Setting::get(self::KEY_MESSAGE, ''));
    }

    /** Public-disk path of the storefront card image, or '' when none is set. */
    public static function imagePath(): string
    {
        try {
            return trim((string) Setting::get(self::KEY_IMAGE, ''));
        } catch (\Throwable) {
            return '';
        }
    }

    /** Storefront card image URL; null lets the page use its default. */
    public static function imageUrl(): ?string
    {
        $path = self::imagePath();

        return $path !== '' ? asset('storage/'.$path) : null;
    }

    public static function saveImagePath(?string $path): void
    {
        Setting::set(self::KEY_IMAGE, (string) $path, self::GROUP);
    }

    /**
     * When the provider reports its wallet too low to pay a bill, new sales pause
     * automatically (customers must not keep paying for bills that cannot be paid)
     * until a provider payment succeeds again or an admin resumes. Existing orders
     * keep retrying with their SAME provider reference.
     */
    public static function providerWalletPausedAt(): ?string
    {
        try {
            $value = trim((string) Setting::get(self::KEY_WALLET_PAUSED, ''));
        } catch (\Throwable) {
            return null;
        }

        return $value !== '' ? $value : null;
    }

    /** @return bool true when this call started a new pause (the caller sends the one alert) */
    public static function pauseForProviderWallet(): bool
    {
        if (self::providerWalletPausedAt() === null) {
            Setting::set(self::KEY_WALLET_PAUSED, now()->toIso8601String(), self::GROUP);
        }

        // One alert per incident, even if several workers hit the empty wallet at once.
        return Cache::add(self::WALLET_ALERT_KEY, 1, now()->addDays(7));
    }

    /** @return bool true when sales were paused and are now resumed */
    public static function resumeProviderWallet(): bool
    {
        $wasPaused = self::providerWalletPausedAt() !== null;
        Setting::set(self::KEY_WALLET_PAUSED, '', self::GROUP);
        Cache::forget(self::WALLET_ALERT_KEY);

        return $wasPaused;
    }

    public static function save(bool $enabled, ?string $message): void
    {
        Setting::set(self::KEY_ENABLED, $enabled ? '1' : '0', self::GROUP);
        Setting::set(self::KEY_MESSAGE, trim((string) $message), self::GROUP);
    }
}
