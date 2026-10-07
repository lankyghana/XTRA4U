<?php

namespace App\Services\UtilityBills;

use App\Models\UtilityBillerConfig;
use App\Services\UtilityBills\Data\Biller;
use App\Services\UtilityBills\Data\Catalog;
use App\Services\UtilityBills\Exceptions\SaleNotAllowed;
use App\Services\UtilityBills\Exceptions\UtilityProviderException;
use Illuminate\Support\Collection;

/**
 * Whether a biller may take a NEW sale:
 *
 *   provider reports it enabled  AND  admin enabled it  AND  service globally enabled
 *
 * An admin toggle can never make a biller operational that the provider has
 * disabled. Disabling anything here never touches existing orders.
 */
class UtilityBillAvailability
{
    public function __construct(private KingFlexyUtilityProvider $provider) {}

    /**
     * Billers a customer may start a purchase for right now.
     *
     * @return Collection<int, array{biller: Biller, config: UtilityBillerConfig}>
     */
    public function sellable(): Collection
    {
        if (! UtilityBillSettings::enabled() || ! $this->provider->isConfigured()) {
            return collect();
        }

        [$catalog] = $this->provider->catalogForDisplay();
        if (! $catalog) {
            return collect();
        }

        $configs = UtilityBillerConfig::query()->where('is_enabled', true)->get()->keyBy('biller_key');

        return collect($catalog->billers)
            ->filter(fn (Biller $b) => $b->enabled && $configs->has($b->key))
            ->map(fn (Biller $b) => ['biller' => $b, 'config' => $configs->get($b->key)])
            ->values();
    }

    /** True when the page should be offered at all (used for storefront/card presence). */
    public function serviceOpen(): bool
    {
        return UtilityBillSettings::enabled();
    }

    /**
     * Authoritative, fresh check used when a lookup or order is created.
     *
     * @return array{0: Biller, 1: UtilityBillerConfig, 2: Catalog}
     *
     * @throws SaleNotAllowed
     */
    public function assertSellable(string $billerKey): array
    {
        if (! UtilityBillSettings::enabled()) {
            throw new SaleNotAllowed(UtilityBillSettings::maintenanceMessage(), 'service_disabled');
        }

        if (! $this->provider->isConfigured()) {
            throw new SaleNotAllowed('Utility bills are not available right now.', 'not_configured');
        }

        try {
            $catalog = $this->provider->catalog();
        } catch (UtilityProviderException $e) {
            throw new SaleNotAllowed($e->userMessage(), 'provider_unavailable');
        }

        $biller = $catalog->biller($billerKey);
        $config = UtilityBillerConfig::query()->where('biller_key', $billerKey)->first();

        if (! $biller || ! $biller->enabled) {
            throw new SaleNotAllowed('This bill service is currently unavailable.', 'biller_provider_disabled');
        }

        if (! $config || ! $config->is_enabled) {
            throw new SaleNotAllowed('This bill service is currently unavailable.', 'biller_admin_disabled');
        }

        return [$biller, $config, $catalog];
    }
}
