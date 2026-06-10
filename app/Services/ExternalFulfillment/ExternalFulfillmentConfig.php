<?php

namespace App\Services\ExternalFulfillment;

use App\Models\Setting;
use App\Models\Vendor;
use App\Models\VendorSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

final class ExternalFulfillmentConfig
{
    public function __construct(
        public ?string $provider, // Deprecated: used only as fallback
        /** @var array<string,array<string,mixed>> */
        public array $providers,
    ) {
    }

    public static function loadFresh(): self
    {
        $settings = Schema::hasTable('settings')
            ? Setting::getGroupFresh('external_fulfillment')
            : [];

        $provider = self::normalizeProvider((string) ($settings['external_fulfillment_provider'] ?? 'datafyhub'));

        $timeout = self::coerceTimeout((int) ($settings['external_fulfillment_timeout_seconds'] ?? 10));

        $providers = [
            'datafyhub' => [
                'enabled' => self::toBool($settings['external_fulfillment_datafyhub_enabled'] ?? $settings['external_fulfillment_enabled'] ?? null),
                'base_url' => trim((string) config('services.external_fulfillment.base_url', '')),
                'endpoint' => trim((string) config('services.external_fulfillment.endpoint', '')),
                'services_endpoint' => trim((string) config('services.external_fulfillment.services_endpoint', '/services')),
                'token' => (string) ($settings['external_fulfillment_token'] ?? ''),
                'timeout_seconds' => $timeout,
            ],
            'xpresportal' => [
                'enabled' => self::toBool($settings['external_fulfillment_xpresportal_enabled'] ?? $settings['external_fulfillment_enabled'] ?? null),
                'base_url' => trim((string) config('services.xpresportal.base_url', '')),
                'endpoint' => '/api/v1/orders',
                'services_endpoint' => trim((string) config('services.xpresportal.services_endpoint', '/api/v1/services')),
                'api_key' => (string) config('services.xpresportal.api_key', ''),
                'api_secret' => (string) config('services.xpresportal.api_secret', ''),
                'timeout_seconds' => self::coerceTimeout((int) config('services.xpresportal.timeout', 30)),
                'environment' => (string) config('services.xpresportal.environment', 'sandbox'),
            ],
            'gigshub' => [
                'enabled' => self::toBool($settings['external_fulfillment_gigshub_enabled'] ?? $settings['external_fulfillment_enabled'] ?? null),
                'base_url' => trim((string) config('services.gigshub.base_url', '')),
                'api_key' => (string) config('services.gigshub.api_key', ''),
                'timeout_seconds' => self::coerceTimeout((int) config('services.gigshub.timeout', 30)),
            ],
        ];

        return new self($provider, $providers);
    }

    public static function loadFreshForVendor(Vendor $vendor): self
    {
        $baseUrlFromConfig = trim((string) config('services.external_fulfillment.base_url', ''));
        $endpointFromConfig = trim((string) config('services.external_fulfillment.endpoint', ''));

        // Business rule: only root vendors can enable external fulfillment.
        if (! is_null($vendor->affiliate_vendor_id)) {
            return new self('datafyhub', [
                'datafyhub' => [
                    'enabled' => false,
                    'base_url' => $baseUrlFromConfig,
                    'endpoint' => $endpointFromConfig,
                    'services_endpoint' => trim((string) config('services.external_fulfillment.services_endpoint', '/services')),
                    'token' => '',
                    'timeout_seconds' => 10,
                ],
            ]);
        }

        if (! Schema::hasTable('vendor_settings')) {
            return new self('datafyhub', [
                'datafyhub' => [
                    'enabled' => false,
                    'base_url' => $baseUrlFromConfig,
                    'endpoint' => $endpointFromConfig,
                    'services_endpoint' => trim((string) config('services.external_fulfillment.services_endpoint', '/services')),
                    'token' => '',
                    'timeout_seconds' => 10,
                ],
            ]);
        }

        $settings = VendorSetting::getGroupFreshForVendor((int) $vendor->id, 'external_fulfillment');

        $globalEnabled = self::toBool($settings['external_fulfillment_enabled'] ?? null);
        $legacyProvider = self::normalizeProvider((string) ($settings['external_fulfillment_provider'] ?? 'datafyhub'));
        
        $datafyhubEnabled = isset($settings['external_fulfillment_datafyhub_enabled']) ? self::toBool($settings['external_fulfillment_datafyhub_enabled']) : ($globalEnabled && $legacyProvider === 'datafyhub');
        $xpresportalEnabled = isset($settings['external_fulfillment_xpresportal_enabled']) ? self::toBool($settings['external_fulfillment_xpresportal_enabled']) : ($globalEnabled && $legacyProvider === 'xpresportal');
        $gigshubEnabled = isset($settings['external_fulfillment_gigshub_enabled']) ? self::toBool($settings['external_fulfillment_gigshub_enabled']) : ($globalEnabled && $legacyProvider === 'gigshub');

        $provider = $legacyProvider;
        $timeout = self::coerceTimeout((int) ($settings['external_fulfillment_timeout_seconds'] ?? 10));

        $vendorXpresBaseUrl = self::nullableTrim((string) ($settings['external_fulfillment.xpres.base_url'] ?? ''));
        $vendorXpresEnvironment = self::nullableTrim((string) ($settings['external_fulfillment.xpres.environment'] ?? ''));

        $vendorXpresApiKey = self::decryptVendorSecret(
            is_string($settings['external_fulfillment.xpres.api_key'] ?? null)
                ? $settings['external_fulfillment.xpres.api_key']
                : null
        );

        $vendorXpresApiSecret = self::decryptVendorSecret(
            is_string($settings['external_fulfillment.xpres.api_secret'] ?? null)
                ? $settings['external_fulfillment.xpres.api_secret']
                : null
        );

        $vendorGigshubBaseUrl = self::nullableTrim((string) ($settings['external_fulfillment.gigshub.base_url'] ?? ''));
        $vendorGigshubApiKey = self::decryptVendorSecret(
            is_string($settings['external_fulfillment.gigshub.api_key'] ?? null)
                ? $settings['external_fulfillment.gigshub.api_key']
                : null
        );
        $systemGigshubBaseUrl = trim((string) config('services.gigshub.base_url', ''));
        $systemGigshubApiKey = trim((string) config('services.gigshub.api_key', ''));

        $providers = [
            'datafyhub' => [
                'enabled' => $globalEnabled && $datafyhubEnabled,
                'base_url' => $baseUrlFromConfig,
                'endpoint' => $endpointFromConfig,
                'services_endpoint' => trim((string) config('services.external_fulfillment.services_endpoint', '/services')),
                'token' => (string) ($settings['external_fulfillment_token'] ?? ''),
                'timeout_seconds' => $timeout,
            ],
            'xpresportal' => [
                'enabled' => $globalEnabled && $xpresportalEnabled,
                'base_url' => $vendorXpresBaseUrl,
                'endpoint' => '/api/v1/orders',
                'services_endpoint' => trim((string) config('services.xpresportal.services_endpoint', '/api/v1/services')),
                'api_key' => $vendorXpresApiKey,
                'api_secret' => $vendorXpresApiSecret,
                'timeout_seconds' => self::coerceTimeout((int) config('services.xpresportal.timeout', 30)),
                'environment' => $vendorXpresEnvironment ?? (string) config('services.xpresportal.environment', 'sandbox'),
            ],
            'gigshub' => [
                'enabled' => $globalEnabled && $gigshubEnabled,
                'base_url' => $vendorGigshubBaseUrl ?? $systemGigshubBaseUrl,
                'api_key' => $vendorGigshubApiKey ?? $systemGigshubApiKey,
                'timeout_seconds' => self::coerceTimeout((int) config('services.gigshub.timeout', 30)),
            ],
        ];

        return new self($provider, $providers);
    }

    public function isReady(): bool
    {
        foreach (['datafyhub', 'xpresportal', 'gigshub'] as $p) {
            if ($this->isProviderReady($p)) {
                return true;
            }
        }
        return false;
    }

    public function isProviderReady(string $provider): bool
    {
        $config = $this->providerConfig($provider);

        if (! ($config['enabled'] ?? false)) {
            return false;
        }

        if ($provider === 'xpresportal') {
            $hasVendorBaseUrl = $config['base_url'] !== '';
            $hasVendorApiKey = $config['api_key'] !== '';
            return $hasVendorBaseUrl && $hasVendorApiKey;
        }

        if ($provider === 'gigshub') {
            return $config['base_url'] !== '' && $config['api_key'] !== '';
        }

        return $config['base_url'] !== ''
            && $config['token'] !== ''
            && $config['endpoint'] !== '';
    }

    public function isServiceDiscoveryReady(): bool
    {
        foreach (['datafyhub', 'xpresportal', 'gigshub'] as $p) {
            if ($this->isProviderServiceDiscoveryReady($p)) {
                return true;
            }
        }
        return false;
    }

    public function isProviderServiceDiscoveryReady(string $provider): bool
    {
        $config = $this->providerConfig($provider);
        if (! ($config['enabled'] ?? false)) {
            return false;
        }

        if ($provider === 'xpresportal') {
            return $config['base_url'] !== '' && $config['api_key'] !== '';
        }

        if ($provider === 'gigshub') {
            return $config['base_url'] !== '' && $config['api_key'] !== '';
        }

        return $config['base_url'] !== ''
            && $config['token'] !== ''
            && $config['endpoint'] !== '';
    }

    public function url(): string
    {
        $config = $this->providerConfig($this->provider);
        if ($config === []) {
            $config = $this->providerConfig('datafyhub');
        }
        return rtrim((string) ($config['base_url'] ?? ''), '/') . '/' . ltrim((string) ($config['endpoint'] ?? ''), '/');
    }

    /** @return array<string,mixed> */
    public function providerConfig(string $provider): array
    {
        return $this->providers[$provider] ?? [];
    }

    public function timeoutSeconds(): int
    {
        $config = $this->providerConfig($this->provider);
        if ($config === []) {
            $config = $this->providerConfig('datafyhub');
        }
        return (int) ($config['timeout_seconds'] ?? 10);
    }

    private static function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private static function coerceTimeout(int $value): int
    {
        return $value > 0 ? $value : 10;
    }

    private static function normalizeProvider(string $provider): string
    {
        $normalized = strtolower(trim($provider));

        return in_array($normalized, ['datafyhub', 'xpresportal', 'gigshub'], true)
            ? $normalized
            : 'datafyhub';
    }

    private static function nullableTrim(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private static function decryptVendorSecret(?string $encrypted): ?string
    {
        if (! is_string($encrypted) || trim($encrypted) === '') {
            return null;
        }

        try {
            $decrypted = Crypt::decryptString($encrypted);
            $decrypted = trim($decrypted);

            return $decrypted !== '' ? $decrypted : null;
        } catch (\Throwable $e) {
            $fallback = trim($encrypted);
            return $fallback !== '' ? $fallback : null;
        }
    }
}
