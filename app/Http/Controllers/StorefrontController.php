<?php

namespace App\Http\Controllers;

use App\Models\NetworkService;
use App\Models\PaymentGatewayConfig;
use App\Models\ResellerProduct;
use App\Models\Vendor;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class StorefrontController extends Controller
{
    public function index()
    {
        return view('storefront.index', [
            'mainStore' => \App\Support\MainStore::vendor(),
        ]);
    }

    public function showVendorStore(Vendor $vendor, ?string $initialCategory = null)
    {
        // Load owned products
        $vendor->load(['products' => function ($query) {
            $query->where('is_active', true);
        }]);

        // Load reseller products with relationships
        $resellerProducts = ResellerProduct::where('reseller_vendor_id', $vendor->id)
            ->where('is_active', true)
            ->with(['product' => function ($query) {
                $query->where('is_active', true)->where('is_resellable', true);
            }, 'ownerVendor'])
            ->get();

        $categoryConfig = config('storefront.categories', []);
        $defaultCategory = config('storefront.default_category')
            ?? (array_key_first($categoryConfig) ?? 'data');

        // Process owned products
        $ownedProducts = $vendor->products->map(function ($product) use ($defaultCategory) {
            $metadata = $this->decodeDescription($product->description);
            $product->structured_metadata = $metadata;
            $product->display_metadata = $metadata;
            $product->display_category = $metadata['category'] ?? $defaultCategory;
            $product->display_service = $metadata['service'] ?? $metadata['network'] ?? $product->name;
            $product->is_reseller_product = false;
            $product->display_price = $product->price;

            return $product;
        });

        // Process reseller products
        $resellerProductsProcessed = $resellerProducts->filter(function ($rp) {
            return $rp->product !== null; // Only include if parent product exists and is active
        })->map(function ($resellerProduct) use ($defaultCategory) {
            $product = $resellerProduct->product;
            $metadata = $this->decodeDescription($product->description);

            // Clone the product and modify it
            $displayProduct = clone $product;
            $displayProduct->id = 'reseller_'.$resellerProduct->id; // Unique ID for reseller product
            $displayProduct->reseller_product_id = $resellerProduct->id;
            $displayProduct->original_product_id = $product->id;
            $displayProduct->structured_metadata = $metadata;
            $displayProduct->display_metadata = $metadata;
            $displayProduct->display_category = $metadata['category'] ?? $defaultCategory;
            $displayProduct->display_service = $metadata['service'] ?? $metadata['network'] ?? $product->name;
            $displayProduct->is_reseller_product = true;
            $displayProduct->display_price = $resellerProduct->selling_price; // Show markup price
            $displayProduct->base_price = $resellerProduct->base_price;
            $displayProduct->markup_price = $resellerProduct->markup_price;
            $displayProduct->owner_vendor_name = $resellerProduct->ownerVendor->business_name;

            return $displayProduct;
        });

        // Combine both product types
        $products = $ownedProducts->concat($resellerProductsProcessed);

        $services = $this->buildServicePayload($products, $defaultCategory);

        // Add Result Checker NetworkServices to the services collection.
        // Only show checkers where the vendor has an active setting (i.e. has configured a profit margin).
        // The displayed price is the vendor's selling price = base_price + profit_amount.
        $vendorCheckerSettings = \App\Models\VendorResultCheckerSetting::where('vendor_id', $vendor->id)
            ->where('is_active', true)
            ->with('service')
            ->get();

        foreach ($vendorCheckerSettings as $setting) {
            $checker = $setting->service;

            // Skip if the underlying service has been deleted, deactivated, or not yet priced by admin.
            if (! $checker || ! $checker->is_active || ! $checker->base_price || $checker->base_price <= 0) {
                continue;
            }

            $sellingPrice = (float) ($checker->base_price ?? 0) + (float) ($setting->profit_amount ?? 0);

            $resultCheckerService = [
                'key' => 'results_checker_'.$checker->id,
                'name' => $checker->name,
                'category' => $checker->category,
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
            ];
            $services->push($resultCheckerService);
        }

        // Add AFA Registration as a service if vendor has it enabled (direct or reseller)
        $afaPrice = null;
        $afaUrl = null;
        $isResellerAfa = false;
        $afaSourceVendorId = null;

        if ($vendor->afa_enabled && $vendor->afa_price > 0) {
            // Direct AFA service
            $afaPrice = (float) $vendor->afa_price;
            $afaUrl = route('afa.register', $vendor->vendor_code);
        } elseif ($vendor->afa_reseller_enabled && $vendor->afa_selling_price > 0 && $vendor->afa_source_vendor_id) {
            // Reseller AFA service
            $afaPrice = (float) $vendor->afa_selling_price;
            $afaUrl = route('afa.register', $vendor->vendor_code);
            $isResellerAfa = true;
            $afaSourceVendorId = $vendor->afa_source_vendor_id;
        }

        if ($afaPrice && $afaUrl) {
            $afaService = [
                'key' => 'afa_registration_service',
                'name' => 'AFA Registration',
                'category' => 'afa',
                'logo' => '/images/afa-logo.png',
                'is_afa' => true,
                'afa_url' => $afaUrl,
                'packages' => [
                    [
                        'id' => 'afa_package',
                        'name' => 'AFA Registration',
                        'price' => $afaPrice,
                        'size' => null,
                        'validity' => null,
                        'tag' => $isResellerAfa ? 'Reseller' : 'Official',
                        'notes' => 'Complete AFA Registration with ID verification',
                        'is_reseller_product' => $isResellerAfa,
                        'is_afa' => true,
                        'afa_url' => $afaUrl,
                    ],
                ],
            ];
            $services->push($afaService);
        }

        $categories = $this->buildGlobalCategoryList($services, $categoryConfig, $defaultCategory);

        return view('vendor_store', [
            'vendor' => $vendor,
            'services' => $services,
            'categories' => $categories,
            // Category to preselect on load (e.g. 'results' for the
            // /store/{vendor}/result-checkers entry point). Null lets the
            // store fall back to the first category with services.
            'initialCategory' => $initialCategory,
            // Admin-controlled per-category open/closed flags for UX (server-side
            // guards at the purchase endpoints are the real enforcement).
            'serviceStatuses' => \App\Support\ServiceAvailability::statuses(),
            'serviceClosedMessage' => \App\Support\ServiceAvailability::message(),
            // Inline/API gateways like BulkClix require a MoMo number (and network) before initiating payment.
            'requiresInlineMomo' => PaymentGatewayConfig::defaultCollectionRequiresPayerPhone(),
        ]);
    }

    public function showResultCheckers(Vendor $vendor)
    {
        return $this->showVendorStore($vendor, 'results');
    }

    /**
     * Legacy vendor-agnostic entry point (kept for bookmarked/external links
     * to /results-checkers). Now forwards to the official, admin-configured
     * platform page instead of a specific vendor's store — that page
     * resolves its own vendor (App\Support\PlatformServiceVendor) and
     * degrades gracefully on its own, so this no longer needs MainStore or
     * a fallback branch.
     */
    public function resultCheckersEntry()
    {
        return redirect()->route('services.result-checkers');
    }

    private function decodeDescription(?string $value): array
    {
        if (! $value) {
            return [];
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded)
            ? $decoded
            : [];
    }

    private function buildServicePayload(Collection $products, string $defaultCategory): Collection
    {
        $grouped = [];

        // Fetch all network services with images for quick lookup
        $networkServices = NetworkService::active()
            ->whereNotNull('image_path')
            ->get()
            ->keyBy(function ($service) {
                return strtolower($service->name);
            });

        foreach ($products as $product) {
            $meta = $product->structured_metadata ?? [];
            $category = $meta['category'] ?? $defaultCategory;
            $serviceName = $product->display_service;
            $serviceKey = md5($category.'::'.$serviceName);

            // Alpine uses `pkg.id` as the x-for key.
            // Guarantee it's always non-empty and unique across owned vs reseller products.
            $rawId = $product->id;
            $isResellerProduct = (bool) ($product->is_reseller_product ?? false);
            $packageId = $isResellerProduct
                ? (string) $rawId
                : ('product_'.(string) $rawId);

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
                // Look up the network service image
                $networkService = $networkServices->get(strtolower($serviceName));
                $logoUrl = $networkService ? $networkService->image_url : null;

                $grouped[$serviceKey] = [
                    'key' => $serviceKey,
                    'name' => $serviceName,
                    'category' => $category,
                    'logo' => $logoUrl,
                    'packages' => [],
                ];
            }

            $grouped[$serviceKey]['packages'][] = $package;
        }

        // Sort packages within each service by price (ascending)
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

    private function buildGlobalCategoryList(Collection $services, array $categoryConfig, string $defaultCategory): Collection
    {
        $categoryMeta = collect($categoryConfig);
        $serviceCounts = $services
            ->groupBy('category')
            ->map(fn ($group) => $group->sum(fn ($service) => count($service['packages'])));

        $globalCategories = $categoryMeta->map(function ($meta, $categoryKey) use ($serviceCounts) {
            $label = $meta['label'] ?? Str::title(str_replace(['-', '_'], ' ', $categoryKey));
            $description = $meta['description'] ?? 'Explore services in the '.Str::lower($label).' category.';

            return [
                'id' => $categoryKey,
                'value' => $categoryKey,
                'label' => $label,
                'description' => $description,
                'serviceCount' => $serviceCounts->get($categoryKey, 0),
            ];
        })->values();

        $vendorOnlyCategories = $services
            ->pluck('category')
            ->unique()
            ->reject(fn ($category) => $categoryMeta->has($category));

        $vendorExtras = $vendorOnlyCategories->map(function ($categoryKey) use ($serviceCounts) {
            $label = Str::title(str_replace(['-', '_'], ' ', $categoryKey));

            return [
                'id' => $categoryKey,
                'value' => $categoryKey,
                'label' => $label,
                'description' => 'Explore services in the '.Str::lower($label).' category.',
                'serviceCount' => $serviceCounts->get($categoryKey, 0),
            ];
        });

        $combined = $globalCategories->concat($vendorExtras);

        if ($combined->isEmpty()) {
            $label = Str::title(str_replace(['-', '_'], ' ', $defaultCategory));
            $combined = collect([
                [
                    'id' => $defaultCategory,
                    'value' => $defaultCategory,
                    'label' => $label,
                    'description' => 'Select a category to explore packages.',
                    'serviceCount' => 0,
                ],
            ]);
        }

        return $combined->values();
    }
}
