<?php

namespace App\Http\Controllers;

use App\Models\AfaRegistration;
use App\Models\PaymentGatewayConfig;
use App\Services\AffiliateChainService;
use App\Support\PlatformServiceCatalog;
use App\Support\PlatformServiceVendor;
use App\Support\ServiceAvailability;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Official XTRA4U service pages (homepage entry points), as distinct from a
 * vendor's own shared storefront link (/store/{vendor_code}). Each page shows
 * exactly one category, sourced from the single vendor an admin has assigned
 * to that category (see App\Support\PlatformServiceVendor) — never every
 * vendor, and never a category the visitor didn't choose.
 *
 * Every purchase still flows through the existing, unmodified commerce
 * stack (CheckoutController::process, ResultCheckerCheckoutController,
 * AfaRegistrationController::store, GatewayManager, PaymentService, etc.);
 * this controller only resolves which vendor's catalog to display.
 */
class PlatformServiceController extends Controller
{
    public function dataBundles()
    {
        return $this->showCategory('data', 'Data Bundles', 'platform-services.data-bundles');
    }

    public function ecg()
    {
        return $this->showCategory('ecg', 'ECG', 'platform-services.ecg');
    }

    public function shop()
    {
        return $this->showCategory('shop', 'Shop Online', 'platform-services.shop');
    }

    public function resultCheckers()
    {
        return $this->showCategory('results', 'Results Checker', 'platform-services.result-checkers');
    }

    /**
     * AFA Registration doesn't use showCategory()/PlatformServiceCatalog:
     * it isn't a Choose Service -> Select Package -> Checkout flow, it's a
     * single registration form. Eligibility/pricing here mirrors
     * AfaRegistrationController::show() (direct vs. reseller AFA), reusing
     * the same AffiliateChainService and AfaRegistration statics it does —
     * but rendering our own, XTRA4U-branded view instead of that
     * controller's vendor-branded one (which links back to the vendor's
     * full storefront, exactly what a platform page must not do).
     */
    public function afaRegistration()
    {
        $category = 'afa';
        $label = 'AFA Registration';

        if (ServiceAvailability::isClosed($category)) {
            return $this->unavailable($label, ServiceAvailability::message());
        }

        $vendor = PlatformServiceVendor::resolve($category);

        if (! $vendor) {
            Log::warning('platform_service.vendor_unresolved', [
                'category' => $category,
                'configured_vendor_id' => PlatformServiceVendor::vendorIdFor($category),
            ]);

            return $this->unavailable($label);
        }

        $afaPrice = null;

        if ($vendor->afa_enabled && (float) $vendor->afa_price > 0) {
            $afaPrice = (float) $vendor->afa_price;
        } elseif ($vendor->afa_reseller_enabled && (float) $vendor->afa_selling_price > 0 && $vendor->afa_source_vendor_id) {
            // Resolving the reseller chain can fail (e.g. the upstream
            // provider was disabled) even when this vendor's own fields look
            // priced — mirrors AfaRegistrationController::show()'s check.
            if ((new AffiliateChainService)->resolveRootAfaProviderFromSeller($vendor)) {
                $afaPrice = (float) $vendor->afa_selling_price;
            }
        }

        if (! $afaPrice || $afaPrice <= 0) {
            Log::warning('platform_service.no_offerings', [
                'category' => $category,
                'vendor_id' => $vendor->id,
            ]);

            return $this->unavailable($label);
        }

        return view('platform-services.afa-registration', [
            'vendor' => $vendor,
            'price' => $afaPrice,
            'regions' => AfaRegistration::getRegions(),
            'idTypes' => AfaRegistration::getIdTypes(),
            'requiresInlineMomo' => PaymentGatewayConfig::defaultCollectionRequiresPayerPhone(),
        ]);
    }

    private function showCategory(string $category, string $label, string $view)
    {
        // An admin may have closed the category entirely — same guard the
        // purchase endpoints already enforce, checked here too so the page
        // itself never renders when the category is closed.
        if (ServiceAvailability::isClosed($category)) {
            return $this->unavailable($label, ServiceAvailability::message());
        }

        $vendor = PlatformServiceVendor::resolve($category);

        if (! $vendor) {
            Log::warning('platform_service.vendor_unresolved', [
                'category' => $category,
                'configured_vendor_id' => PlatformServiceVendor::vendorIdFor($category),
            ]);

            return $this->unavailable($label);
        }

        $services = $this->catalogFor($category, $vendor);

        if ($services->isEmpty()) {
            Log::warning('platform_service.no_offerings', [
                'category' => $category,
                'vendor_id' => $vendor->id,
            ]);

            return $this->unavailable($label);
        }

        return view($view, [
            'vendor' => $vendor,
            'services' => $services,
            'category' => $category,
            'label' => $label,
            'categoryConfig' => config('storefront.categories', [])[$category] ?? [],
            'requiresInlineMomo' => PaymentGatewayConfig::defaultCollectionRequiresPayerPhone(),
        ]);
    }

    private function catalogFor(string $category, $vendor): Collection
    {
        return match ($category) {
            'results' => PlatformServiceCatalog::resultCheckersForVendor($vendor),
            default => PlatformServiceCatalog::forVendor($vendor, $category),
        };
    }

    private function unavailable(string $label, ?string $message = null)
    {
        return response()->view('pages.services-closed', [
            'title' => $label.' Unavailable',
            'message' => $message ?? 'This service is temporarily unavailable. Please try again later.',
            'backHref' => route('storefront.index'),
        ], 503);
    }
}
