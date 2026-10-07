<?php

namespace App\Services\UtilityBills;

use App\Models\Setting;

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

    /** Off until an admin deliberately enables it. */
    public static function enabled(): bool
    {
        return (string) Setting::get(self::KEY_ENABLED, '0') === '1';
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

    public static function save(bool $enabled, ?string $message): void
    {
        Setting::set(self::KEY_ENABLED, $enabled ? '1' : '0', self::GROUP);
        Setting::set(self::KEY_MESSAGE, trim((string) $message), self::GROUP);
    }
}
