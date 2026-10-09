<?php

namespace App\Services\UtilityBills;

use App\Models\Setting;
use App\Models\UtilityBillConfigAudit;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Provider API key resolution. An admin-saved key (encrypted with APP_KEY in the `settings`
 * table) wins; otherwise the KINGFLEXY_UTILITIES_API_KEY env/config value is used.
 *
 * The key is never returned to a view, written to an audit row or logged: the admin UI only
 * ever sees source + last four characters.
 */
class UtilityBillCredentials
{
    public const KEY = 'utility_bills.api_key';

    public const GROUP = 'utility_bills';

    public static function apiKey(): string
    {
        $stored = self::storedKey();

        return $stored !== '' ? $stored : (string) config('services.kingflexy_utilities.api_key');
    }

    /** @return array{source:string, last4:?string} source: admin | env | none */
    public static function describe(): array
    {
        $stored = self::storedKey();
        if ($stored !== '') {
            return ['source' => 'admin', 'last4' => substr($stored, -4)];
        }

        $env = (string) config('services.kingflexy_utilities.api_key');

        return $env !== '' ? ['source' => 'env', 'last4' => substr($env, -4)] : ['source' => 'none', 'last4' => null];
    }

    /** Only provider Commission keys are usable on the utility endpoints. */
    public static function isValidFormat(string $key): bool
    {
        return str_starts_with($key, 'kf_cs_') && strlen($key) >= 12 && preg_match('/^[A-Za-z0-9_\-]+$/', $key) === 1;
    }

    /**
     * @param  array{id:?int,email:?string,ip:?string}  $actor
     */
    public static function save(?string $key, array $actor): void
    {
        $key = $key === null ? null : trim($key);
        $before = self::describe();

        DB::transaction(function () use ($key, $actor, $before) {
            Setting::set(self::KEY, $key === null || $key === '' ? '' : Crypt::encryptString($key), self::GROUP);

            UtilityBillConfigAudit::create([
                'admin_id' => $actor['id'] ?? null,
                'admin_email' => $actor['email'] ?? null,
                'scope' => 'credentials',
                'biller_key' => null,
                'old_values' => $before,
                'new_values' => self::describe(),
                'ip_address' => $actor['ip'] ?? null,
            ]);
        });
    }

    private static function storedKey(): string
    {
        try {
            $raw = (string) Setting::get(self::KEY, '');

            return $raw === '' ? '' : Crypt::decryptString($raw);
        } catch (\Throwable) {
            // Unreadable (e.g. APP_KEY rotated): fall back to env rather than sending garbage.
            return '';
        }
    }
}
