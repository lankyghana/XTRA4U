<?php

namespace App\Http\Controllers;

use App\Models\NetworkService;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\Vendor;
use App\Services\ExternalFulfillment\ExternalFulfillmentConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index()
    {
        $vendor = $this->resolveVendor();
        $products = Product::where('vendor_id', $vendor->id)
            ->latest()
            ->paginate(12);

        return view('vendor.products.index', compact('vendor', 'products'));
    }

    // Show create product form
    public function create()
    {
        $vendor = $this->resolveVendor();
        $networkOptions = $this->getNetworkOptions();
        [$activeExternalFulfillmentProvider, $providerNetworks] = $this->resolveExternalFulfillmentContext($vendor);

        return view('product_create', [
            'networkOptions' => $networkOptions,
            'activeExternalFulfillmentProvider' => $activeExternalFulfillmentProvider,
            'providerNetworks' => $providerNetworks,
            'externalServicesEndpoint' => route('vendor.external-services.index'),
        ]);
    }

    // Store new product
    public function store(Request $request)
    {
        $vendor = $this->resolveVendor();
        [$activeExternalFulfillmentProvider, $providerNetworks] = $this->resolveExternalFulfillmentContext($vendor);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'price' => 'required|numeric|min:0',
            'network' => $this->networkValidationRule(),
            'external_network' => $this->externalNetworkValidationRule($providerNetworks),
            'external_service_id' => 'nullable|string|max:120',
            'external_service_name' => 'nullable|string|max:255',
            'external_service_network' => $this->externalNetworkValidationRule($providerNetworks),
            'external_service_capacity' => 'nullable|string|max:80',
            'external_service_price' => 'nullable|numeric|min:0',
            'category' => $this->categoryValidationRule(),
            'size' => 'nullable|string|max:50',
            'validity' => 'nullable|string|max:50',
            'tag' => 'nullable|string|max:80',
            'notes' => 'nullable|string|max:255',
        ]);

        Product::create([
            'vendor_id' => $vendor->id,
            'name' => $validated['name'],
            'description' => $this->buildStructuredDescription(
                $request,
                $validated['description'] ?? null,
                $vendor,
                $activeExternalFulfillmentProvider,
            ),
            'price' => $validated['price'],
            'is_active' => true,
        ]);

        return redirect()->route('vendor.products.index')->with('success', 'Product created successfully.');
    }

    // Show edit product form
    public function edit($id)
    {
        $product = $this->findVendorProduct($id);
        $metadata = $this->decodeDescription($product->description);
        $networkOptions = $this->getNetworkOptions();
        $vendor = $this->resolveVendor();
        [$activeExternalFulfillmentProvider, $providerNetworks] = $this->resolveExternalFulfillmentContext($vendor);

        return view('product_edit', [
            'product' => $product,
            'metadata' => $metadata,
            'networkOptions' => $networkOptions,
            'activeExternalFulfillmentProvider' => $activeExternalFulfillmentProvider,
            'providerNetworks' => $providerNetworks,
            'externalServicesEndpoint' => route('vendor.external-services.index'),
        ]);
    }

    // Update product
    public function update(Request $request, $id)
    {
        $product = $this->findVendorProduct($id);
        $metadata = $this->decodeDescription($product->description);
        $existingNetwork = $metadata['network'] ?? null;

        $vendor = $this->resolveVendor();
        [$activeExternalFulfillmentProvider, $providerNetworks] = $this->resolveExternalFulfillmentContext($vendor);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'price' => 'required|numeric|min:0',
            'network' => $this->networkValidationRule($existingNetwork),
            'external_network' => $this->externalNetworkValidationRule($providerNetworks),
            'external_service_id' => 'nullable|string|max:120',
            'external_service_name' => 'nullable|string|max:255',
            'external_service_network' => $this->externalNetworkValidationRule($providerNetworks),
            'external_service_capacity' => 'nullable|string|max:80',
            'external_service_price' => 'nullable|numeric|min:0',
            'category' => $this->categoryValidationRule(),
            'size' => 'nullable|string|max:50',
            'validity' => 'nullable|string|max:50',
            'tag' => 'nullable|string|max:80',
            'notes' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
        ]);

        $oldPrice = $product->price;
        $newPrice = $validated['price'];

        $product->update([
            'name' => $validated['name'],
            'price' => $newPrice,
            'description' => $this->buildStructuredDescription(
                $request,
                $validated['description'] ?? null,
                $vendor,
                $activeExternalFulfillmentProvider,
                $metadata,
            ),
            'is_active' => $request->boolean('is_active', $product->is_active),
        ]);

        // Sync base_price for all resellers when owner changes price
        if ($oldPrice != $newPrice) {
            ResellerProduct::where('product_id', $product->id)
                ->get()
                ->each(function ($resellerProduct) use ($newPrice) {
                    $resellerProduct->update([
                        'base_price' => $newPrice,
                        // selling_price auto-calculated in model boot()
                    ]);
                });
        }

        return redirect()->route('vendor.products.index')->with('success', 'Product updated successfully.');
    }

    // Delete product
    public function destroy($id)
    {
        $product = $this->findVendorProduct($id);

        // ResellerProduct rows are removed by FK cascade (see migration).
        $product->delete();

        return redirect()->route('vendor.products.index')->with('success', 'Product deleted successfully.');
    }

    protected function resolveVendor()
    {
        $vendorGuardUser = Auth::guard('vendor')->user();
        $user = Auth::user();

        $vendor = $vendorGuardUser instanceof \App\Models\Vendor
            ? $vendorGuardUser
            : ($user instanceof \App\Models\Vendor ? $user : null);

        abort_unless($vendor, 403, 'Vendor account required.');

        return $vendor;
    }

    protected function findVendorProduct($id): Product
    {
        $vendor = $this->resolveVendor();
        return Product::where('vendor_id', $vendor->id)->findOrFail($id);
    }

    protected function categoryValidationRule(): string
    {
        return 'nullable|string|in:' . implode(',', $this->availableCategoryKeys());
    }

    protected function availableCategoryKeys(): array
    {
        $keys = array_keys(config('storefront.categories', []));
        return $keys ?: ['data'];
    }

    protected function getNetworkOptions()
    {
        return NetworkService::active()
            ->orderBy('category')
            ->orderBy('name')
            ->get();
    }

    protected function networkValidationRule(?string $currentNetwork = null): string
    {
        $names = $this->getNetworkOptions()
            ->pluck('name')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        if ($currentNetwork && ! in_array($currentNetwork, $names, true)) {
            $names[] = $currentNetwork;
        }

        if (empty($names)) {
            return 'nullable|string|max:60';
        }

        return 'nullable|string|in:' . implode(',', $names);
    }

    protected function defaultCategory(): string
    {
        $categories = config('storefront.categories', []);

        return config('storefront.default_category')
            ?? array_key_first($categories)
            ?? 'data';
    }

    protected function buildStructuredDescription(
        Request $request,
        ?string $plainDescription,
        Vendor $vendor,
        ?string $activeExternalFulfillmentProvider = null,
        array $existingMetadata = [],
    ): ?string
    {
        $externalMappings = [];
        if (isset($existingMetadata['external_mappings']) && is_array($existingMetadata['external_mappings'])) {
            $externalMappings = $existingMetadata['external_mappings'];
        }

        $activeProvider = $activeExternalFulfillmentProvider ?? 'datafyhub';
        $externalNetwork = is_string($request->input('external_network'))
            ? trim((string) $request->input('external_network'))
            : null;
        $externalServiceId = trim((string) $request->input('external_service_id', ''));
        $externalServiceName = trim((string) $request->input('external_service_name', ''));
        $externalServiceNetwork = trim((string) $request->input('external_service_network', ''));
        $externalServiceCapacity = trim((string) $request->input('external_service_capacity', ''));
        $externalServicePrice = $request->input('external_service_price');

        if ($activeProvider !== '') {
            $providerMapping = [];
            if (isset($externalMappings[$activeProvider]) && is_array($externalMappings[$activeProvider])) {
                $providerMapping = $externalMappings[$activeProvider];
            }

            if ($externalNetwork !== null && $externalNetwork !== '') {
                $providerMapping['network'] = $externalNetwork;
            } else {
                unset($providerMapping['network']);
            }

            if ($externalServiceId !== '') {
                $providerMapping['service_id'] = $externalServiceId;

                if ($externalServiceName !== '') {
                    $providerMapping['service_name'] = $externalServiceName;
                } else {
                    unset($providerMapping['service_name']);
                }

                $mappedNetwork = $externalServiceNetwork !== '' ? $externalServiceNetwork : $externalNetwork;
                if ($mappedNetwork !== null && $mappedNetwork !== '') {
                    $providerMapping['network'] = $mappedNetwork;
                }

                if ($externalServiceCapacity !== '') {
                    $providerMapping['capacity'] = $externalServiceCapacity;
                } else {
                    unset($providerMapping['capacity']);
                }

                if ($externalServicePrice !== null && $externalServicePrice !== '' && is_numeric($externalServicePrice)) {
                    $providerMapping['price'] = (float) $externalServicePrice;
                } else {
                    unset($providerMapping['price']);
                }
            } else {
                unset($providerMapping['service_id'], $providerMapping['service_name'], $providerMapping['capacity'], $providerMapping['price']);
            }

            if (! empty($providerMapping)) {
                $externalMappings[$activeProvider] = $providerMapping;
            } else {
                unset($externalMappings[$activeProvider]);
            }
        }

        $payload = [
            'network' => $request->input('network'),
            'external_network' => $externalNetwork,
            'external_mappings' => empty($externalMappings) ? null : $externalMappings,
            'category' => $request->input('category', $this->defaultCategory()),
            'size' => $request->input('size'),
            'validity' => $request->input('validity'),
            'tag' => $request->input('tag'),
            'notes' => $request->input('notes'),
            'description' => $plainDescription,
        ];

        $filtered = array_filter($payload, function ($value) {
            return ! is_null($value) && $value !== '';
        });

        if (empty($filtered)) {
            return null;
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    protected function decodeDescription(?string $value): array
    {
        if (! $value) {
            return [];
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded)
            ? $decoded
            : [];
    }

    protected function resolveExternalFulfillmentContext(Vendor $vendor): array
    {
        $config = ExternalFulfillmentConfig::loadFreshForVendor($vendor);
        $activeProvider = $config->provider ?? 'datafyhub';
        $providerNetworks = config("external_fulfillment.providers.{$activeProvider}.networks", []);

        return [$activeProvider, $providerNetworks];
    }

    protected function externalNetworkValidationRule(array $providerNetworks): string
    {
        if (! empty($providerNetworks)) {
            return 'nullable|string|in:' . implode(',', $providerNetworks);
        }

        return 'nullable|string|max:60';
    }
}
