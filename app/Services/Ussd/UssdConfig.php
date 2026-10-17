<?php

namespace App\Services\Ussd;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Typed, single-source reader for the `ussd` settings group.
 *
 * Everything the USSD channel needs is admin-configurable and lives in the
 * `settings` table. Nothing here reads config files or environment variables.
 */
class UssdConfig
{
    public const DEFAULT_SESSION_TIMEOUT_SECONDS = 240;

    public const DEFAULT_MAX_REQUESTS_PER_SESSION = 20;

    public const DEFAULT_MAX_RETRY_ATTEMPTS = 3;

    public function isEnabled(): bool
    {
        return Setting::get('ussd_enabled', '1') === '1';
    }

    public function baseCode(): string
    {
        // Fall back to the legacy display-only key so an installation that only
        // ever filled in `ussd_service_code` still produces a usable code.
        $base = trim((string) Setting::get('ussd_base_code', ''));

        return $base !== '' ? $base : trim((string) Setting::get('ussd_service_code', ''));
    }

    public function provider(): string
    {
        return (string) Setting::get('ussd_provider', 'moolre');
    }

    public function welcomeMessage(): string
    {
        return (string) Setting::get('ussd_welcome_message', 'Welcome to XTRA4U');
    }

    public function supportNumber(): string
    {
        return (string) Setting::get('ussd_support_number', '');
    }

    public function sessionTimeoutSeconds(): int
    {
        return $this->positiveInt('ussd_session_timeout_seconds', self::DEFAULT_SESSION_TIMEOUT_SECONDS);
    }

    public function maxRequestsPerSession(): int
    {
        return $this->positiveInt('ussd_max_requests_per_session', self::DEFAULT_MAX_REQUESTS_PER_SESSION);
    }

    public function maxRetryAttempts(): int
    {
        return $this->positiveInt('ussd_max_retry_attempts', self::DEFAULT_MAX_RETRY_ATTEMPTS);
    }

    public function defaultVendorId(): ?int
    {
        $value = Setting::get('ussd_default_vendor_id', '');

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Source IPs permitted to call POST /api/ussd. An empty list means no IP
     * restriction is applied.
     *
     * @return array<int, string>
     */
    public function gatewayIpAllowlist(): array
    {
        $raw = (string) Setting::get('ussd_gateway_ip_allowlist', '');
        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * Shared secret the gateway must present. Stored encrypted at rest; older
     * installations may hold it in plaintext, so decryption failure falls back
     * to the raw value rather than locking the channel out.
     */
    public function gatewaySecret(): string
    {
        $stored = (string) Setting::get('ussd_gateway_secret', '');
        if ($stored === '') {
            return '';
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException $e) {
            return $stored;
        }
    }

    public function hasGatewaySecret(): bool
    {
        return $this->gatewaySecret() !== '';
    }

    // ------------------------------------------------------------ GWO SEVO
    // Provider-specific and deliberately separate from the Moolre-style keys
    // above: the two gateways never share credentials or allowlists.

    public function gwoSevoEnabled(): bool
    {
        return Setting::get('ussd_gwosevo_enabled', '0') === '1';
    }

    /**
     * The vendor every GWO SEVO call is served for. GWO SEVO does not document
     * how the dialled code reaches us, so the vendor cannot be read from the
     * request; it is pinned by the admin instead.
     */
    public function gwoSevoVendorId(): ?int
    {
        $value = Setting::get('ussd_gwosevo_vendor_id', '');

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * @return array<int, string>
     */
    public function gwoSevoIpAllowlist(): array
    {
        $raw = (string) Setting::get('ussd_gwosevo_ip_allowlist', '');

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    public function gwoSevoSecret(): string
    {
        $stored = (string) Setting::get('ussd_gwosevo_secret', '');
        if ($stored === '') {
            return '';
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException $e) {
            return $stored;
        }
    }

    public function hasGwoSevoSecret(): bool
    {
        return $this->gwoSevoSecret() !== '';
    }

    private function positiveInt(string $key, int $default): int
    {
        $value = Setting::get($key, null);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }
}
