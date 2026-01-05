<?php

namespace App\Services\ExternalFulfillment;

use App\Models\Setting;
use App\Models\Vendor;
use App\Models\VendorSetting;
use Illuminate\Support\Facades\Schema;

final class ExternalFulfillmentConfig
{
    public function __construct(
        public bool $enabled,
        public string $baseUrl,
        public string $token,
        public string $endpoint,
        public int $timeoutSeconds,
    ) {
    }

    public static function loadFresh(): self
    {
        $baseUrlFromConfig = trim((string) config('services.external_fulfillment.base_url', ''));
        $endpointFromConfig = trim((string) config('services.external_fulfillment.endpoint', ''));

        if (! Schema::hasTable('settings')) {
            return new self(false, $baseUrlFromConfig, '', $endpointFromConfig, 10);
        }

        $settings = Setting::getGroupFresh('external_fulfillment');

        $enabledRaw = $settings['external_fulfillment_enabled'] ?? null;
        $enabled = filter_var($enabledRaw, FILTER_VALIDATE_BOOLEAN);

        $timeoutRaw = $settings['external_fulfillment_timeout_seconds'] ?? 10;
        $timeout = (int) $timeoutRaw;
        if ($timeout <= 0) {
            $timeout = 10;
        }

        $baseUrl = $baseUrlFromConfig !== ''
            ? $baseUrlFromConfig
            : trim((string) ($settings['external_fulfillment_base_url'] ?? ''));

        $endpoint = $endpointFromConfig !== ''
            ? $endpointFromConfig
            : trim((string) ($settings['external_fulfillment_endpoint'] ?? ''));

        return new self(
            (bool) $enabled,
            $baseUrl,
            (string) ($settings['external_fulfillment_token'] ?? ''),
            $endpoint,
            $timeout,
        );
    }

    public static function loadFreshForVendor(Vendor $vendor): self
    {
        $baseUrlFromConfig = trim((string) config('services.external_fulfillment.base_url', ''));
        $endpointFromConfig = trim((string) config('services.external_fulfillment.endpoint', ''));

        // Business rule: only root vendors can enable external fulfillment.
        if (! is_null($vendor->affiliate_vendor_id)) {
            return new self(false, $baseUrlFromConfig, '', $endpointFromConfig, 10);
        }

        if (! Schema::hasTable('vendor_settings')) {
            return new self(false, $baseUrlFromConfig, '', $endpointFromConfig, 10);
        }

        $settings = VendorSetting::getGroupFreshForVendor((int) $vendor->id, 'external_fulfillment');

        $enabledRaw = $settings['external_fulfillment_enabled'] ?? null;
        $enabled = filter_var($enabledRaw, FILTER_VALIDATE_BOOLEAN);

        $timeoutRaw = $settings['external_fulfillment_timeout_seconds'] ?? 10;
        $timeout = (int) $timeoutRaw;
        if ($timeout <= 0) {
            $timeout = 10;
        }

        return new self(
            (bool) $enabled,
            $baseUrlFromConfig,
            (string) ($settings['external_fulfillment_token'] ?? ''),
            $endpointFromConfig,
            $timeout,
        );
    }

    public function isReady(): bool
    {
        return $this->enabled
            && $this->baseUrl !== ''
            && $this->token !== ''
            && $this->endpoint !== '';
    }

    public function url(): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($this->endpoint, '/');
    }
}
