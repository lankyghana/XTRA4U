<?php

namespace App\Support;

use App\Models\NetworkService;
use App\Models\ResellerProduct;
use App\Models\Vendor;
use App\Models\VendorResultCheckerSetting;
use Illuminate\Support\Collection;

/**
 * Builds the service/package list for a single vendor, scoped to exactly one
 * storefront category. Used by the platform service pages
 * (App\Http\Controllers\PlatformServiceController) so a customer arriving
 * via /services/{category} only ever receives that one category's products
 * — filtered server-side before any grouping happens, never sent the
 * vendor's other categories and hidden client-side.
 *
 * Mirrors the product/metadata handling in
 * StorefrontController::showVendorStore() (decode JSON description
 * metadata, combine owned + reseller products, group into service/package
 * arrays), scoped to one category from the start. Deliberately kept
 * separate from StorefrontController — which powers the live, unrelated
 * /store/{vendor_code} experience — rather than refactored into it, so that
 * proven, heavily-used controller is not touched by this feature.
 */
class PlatformServiceCatalog
{
    public static function forVendor(Vendor $vendor, string $category): Collection
    {
        $vendor->loadMissing(['products' => fn ($query) => $query->where('is_active', true)]);

        $resellerProducts = ResellerProduct::where('reseller_vendor_id', $vendor->id)
            ->where('is_active', true)
            ->with(['product' => fn ($query) => $query->where('is_active', true)->where('is_resellable', true), 'ownerVendor'])
            ->get();

        $defaultCategory = (string) (config('storefront.default_category')
            ?? (array_key_first(config('storefront.categories', [])) ?? 'data'));

        $ownedProducts = $vendor->products
            ->map(fn ($product) => self::decorateOwned($product, $defaultCategory))
            ->filter(fn ($product) => $product->display_category === $category);

        $resellerProductsProcessed = $resellerProducts
            ->filter(fn ($rp) => $rp->product !== null)
            ->map(fn ($rp) => self::decorateReseller($rp, $defaultCategory))
            ->filter(fn ($product) => $product->display_category === $category);

        $products = $ownedProducts->concat($resellerProductsProcessed)->values();

        return self::groupIntoServices($products, $category);
    }

    /**
     * The 'results' category's service/package list — Result Checker PINs
     * are NOT App\Models\Product rows, so this doesn't use forVendor()'s
     * query at all. Mirrors the result-checker block already inside
     * StorefrontController::showVendorStore() (same pricing display:
     * base_price + vendor's profit_amount), scoped to this one vendor and
     * pre-filtered to active, priced, 'results'-category services.
     *
     * The price shown here is a preview only — the authoritative charge is
     * (re)computed server-side, unchanged, by
     * ResultCheckerCheckoutController::initiateCheckout() at purchase time.
     */
    public static function resultCheckersForVendor(Vendor $vendor): Collection
    {
        $settings = VendorResultCheckerSetting::where('vendor_id', $vendor->id)
            ->where('is_active', true)
            ->with('service')
            ->get();

        $services = collect();

        foreach ($settings as $setting) {
            $checker = $setting->service;

            if (! $checker || ! $checker->is_active || $checker->category !== 'results') {
                continue;
            }

            if (! $checker->base_price || $checker->base_price <= 0) {
                continue;
            }

            $sellingPrice = (float) $checker->base_price + (float) ($setting->profit_amount ?? 0);

            $services->push([
                'key' => 'results_checker_'.$checker->id,
                'name' => $checker->name,
                'category' => 'results',
                'logo' => $checker->image_url ?? '/images/default-provider.png',
                'is_results_checker' => true,
                'packages' => [
                    [
                        'id' => 'result_checker_'.$checker->id,
                        'name' => $checker->name,
                        'price' => $sellingPrice,
                        'size' => null,
                        'validity' => null,
                        'tag' => null,
                        'notes' => $checker->description ?? 'Result Checker Service',
                        'is_results_checker' => true,
                        'service_id' => $checker->id,
                    ],
                ],
            ]);
        }

        return $services->values();
    }

    private static function decorateOwned($product, string $defaultCategory)
    {
        $metadata = self::decodeDescription($product->description);
        $product->structured_metadata = $metadata;
        $product->display_metadata = $metadata;
        $product->display_category = $metadata['category'] ?? $defaultCategory;
        $product->display_service = $metadata['service'] ?? $metadata['network'] ?? $product->name;
        $product->is_reseller_product = false;
        $product->display_price = $product->price;

        return $product;
    }

    private static function decorateReseller($resellerProduct, string $defaultCategory)
    {
        $product = $resellerProduct->product;
        $metadata = self::decodeDescription($product->description);

        $displayProduct = clone $product;
        $displayProduct->id = 'reseller_'.$resellerProduct->id;
        $displayProduct->reseller_product_id = $resellerProduct->id;
        $displayProduct->original_product_id = $product->id;
        $displayProduct->structured_metadata = $metadata;
        $displayProduct->display_metadata = $metadata;
        $displayProduct->display_category = $metadata['category'] ?? $defaultCategory;
        $displayProduct->display_service = $metadata['service'] ?? $metadata['network'] ?? $product->name;
        $displayProduct->is_reseller_product = true;
        $displayProduct->display_price = $resellerProduct->selling_price;
        $displayProduct->base_price = $resellerProduct->base_price;
        $displayProduct->markup_price = $resellerProduct->markup_price;
        $displayProduct->owner_vendor_name = $resellerProduct->ownerVendor->business_name;

        return $displayProduct;
    }

    private static function groupIntoServices(Collection $products, string $category): Collection
    {
        $grouped = [];

        $networkServices = NetworkService::active()
            ->whereNotNull('image_path')
            ->get()
            ->keyBy(fn ($service) => strtolower($service->name));

        foreach ($products as $product) {
            $meta = $product->structured_metadata ?? [];
            $serviceName = $product->display_service;
            $serviceKey = md5($category.'::'.$serviceName);

            $rawId = $product->id;
            $isResellerProduct = (bool) ($product->is_reseller_product ?? false);
            $packageId = $isResellerProduct ? (string) $rawId : ('product_'.(string) $rawId);

            $package = [
                'id' => $packageId,
                'name' => $product->name,
                'price' => (float) ($product->display_price ?? $product->price),
                'size' => $meta['size'] ?? null,
                'validity' => $meta['validity'] ?? null,
                'tag' => $meta['tag'] ?? $meta['promo'] ?? null,
                'notes' => $meta['notes'] ?? $product->description,
                'is_reseller_product' => $isResellerProduct,
                'reseller_product_id' => $product->reseller_product_id ?? null,
                'original_product_id' => $product->original_product_id ?? $product->id,
            ];

            if (! isset($grouped[$serviceKey])) {
                $networkService = $networkServices->get(strtolower($serviceName));

                $grouped[$serviceKey] = [
                    'key' => $serviceKey,
                    'name' => $serviceName,
                    'category' => $category,
                    'logo' => $networkService ? $networkService->image_url : null,
                    'packages' => [],
                ];
            }

            $grouped[$serviceKey]['packages'][] = $package;
        }

        foreach ($grouped as &$service) {
            usort($service['packages'], function ($a, $b) {
                $ap = (float) ($a['price'] ?? 0);
                $bp = (float) ($b['price'] ?? 0);

                if ($ap === $bp) {
                    return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
                }

                return $ap <=> $bp;
            });
        }
        unset($service);

        return collect($grouped)->values();
    }

    private static function decodeDescription(?string $value): array
    {
        if (! $value) {
            return [];
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
    }
}
