<?php

namespace App\Services\Ai\VendorAssistant\Tools;

use App\Models\Product;
use App\Models\ResellerProduct;
use App\Services\Ai\Tools\Contracts\AiTool;
use App\Services\Ai\Tools\Schema\ToolParameter;
use App\Services\Ai\Tools\Schema\ToolSchema;
use App\Services\Ai\Tools\ToolArguments;
use App\Services\Ai\Tools\ToolContext;
use App\Services\Ai\VendorAssistant\Tools\Concerns\VendorSurfaceTool;
use App\Services\Vendor\VendorDashboardReadService;

/**
 * The vendor's sellable catalogue: the products it owns, plus the listings it resells from other vendors. Both are
 * queried by the trusted vendor id (VendorDashboardReadService::ownProductsQuery / resellerListingsQuery - the same
 * queries the dashboard and "My Reseller Products" use). "Publicly available" means exactly what the storefront
 * decides: an owned product is on sale when it is active; a reseller listing is on sale when the listing is active AND
 * its source product still exists, is active and is resellable.
 *
 * Only name, category, network, size, validity, price and the on/off flags are returned. The product description
 * column also holds private provider mappings; it is decoded here for four public keys and never returned.
 */
final class ListMyProductsTool implements AiTool
{
    use VendorSurfaceTool;

    public const NAME = 'vendor_list_my_products';

    public const MAX_LIMIT = 20;

    public const STATUSES = ['all', 'active', 'inactive'];

    public function __construct(private readonly VendorDashboardReadService $dashboard) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Lists your own products and the products you resell, with price and whether each is currently on sale in your store. Filter by status or name. At most 20 rows.';
    }

    public function schema(): ToolSchema
    {
        return new ToolSchema(
            ToolParameter::enum('status', 'active = on sale in your store, inactive = not on sale. Default all.', self::STATUSES),
            ToolParameter::string('name', 'Part of the product name.', false, 60, 2),
            ToolParameter::integer('limit', 'How many rows to return (1-'.self::MAX_LIMIT.', default 10).', 1, self::MAX_LIMIT),
        );
    }

    public function handle(ToolArguments $arguments, ToolContext $context): array
    {
        $vendorId = (int) $context->vendorId;
        $status = $arguments->string('status', 'all');
        $limit = $arguments->int('limit', 10);
        $like = $arguments->has('name')
            ? '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $arguments->string('name')).'%'
            : null;

        $own = $this->dashboard->ownProductsQuery($vendorId)
            ->select(['id', 'vendor_id', 'name', 'description', 'price', 'is_active'])
            ->when($status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($like !== null, fn ($q) => $q->where('name', 'like', $like))
            ->orderBy('name')->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $listings = $this->dashboard->resellerListingsQuery($vendorId)
            ->select(['id', 'product_id', 'reseller_vendor_id', 'selling_price', 'is_active'])
            ->with('product:id,vendor_id,name,description,is_active,is_resellable')
            ->when($status === 'active', fn ($q) => $q->where('is_active', true)
                ->whereHas('product', fn ($p) => $p->where('is_active', true)->where('is_resellable', true)))
            ->when($status === 'inactive', fn ($q) => $q->where(fn ($q2) => $q2->where('is_active', false)
                ->orWhereDoesntHave('product', fn ($p) => $p->where('is_active', true)->where('is_resellable', true))))
            ->when($like !== null, fn ($q) => $q->whereHas('product', fn ($p) => $p->where('name', 'like', $like)))
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $rows = $own->map(fn (Product $p) => $this->ownRow($p))
            ->concat($listings->map(fn (ResellerProduct $l) => $this->listingRow($l)));

        $counts = $this->dashboard->catalogueCounts($vendorId);

        return [
            'currency' => VendorToolSupport::CURRENCY,
            'counts' => [
                'own_products_on_sale' => $counts['own_active'],
                'own_products_not_on_sale' => $counts['own_inactive'],
                'resold_listings' => $counts['listings_total'],
                'resold_listings_on_sale' => $counts['listings_available'],
            ],
            'returned' => min($rows->count(), $limit),
            'has_more' => $rows->count() > $limit,
            'products' => $rows->take($limit)->values()->all(),
            'note' => 'on_sale follows your storefront. Products you resell go off sale when the owner deactivates or withdraws them.',
        ];
    }

    /** @return array<string,mixed> */
    private function ownRow(Product $product): array
    {
        return [
            'kind' => 'own_product',
            'name' => VendorToolSupport::text($product->name, 80),
            ...$this->publicMeta($product),
            'price' => VendorToolSupport::money($product->price),
            'is_active' => $product->is_active === true,
            'on_sale' => $product->is_active === true,
        ];
    }

    /** @return array<string,mixed> */
    private function listingRow(ResellerProduct $listing): array
    {
        $source = $listing->product;
        $available = $this->dashboard->listingIsPubliclyAvailable($listing);

        $reason = match (true) {
            $available => null,
            $source === null => 'The product you resell was withdrawn by its owner.',
            $listing->is_active !== true => 'You switched this listing off.',
            $source->is_active !== true => 'The owner switched this product off.',
            default => 'The owner no longer allows resale of this product.',
        };

        return [
            'kind' => 'resold_listing',
            'name' => $source === null ? 'Unavailable product' : VendorToolSupport::text($source->name, 80),
            ...($source === null ? [] : $this->publicMeta($source)),
            'price' => VendorToolSupport::money($listing->selling_price),
            'is_active' => $listing->is_active === true,
            'on_sale' => $available,
            'reason_not_on_sale' => $reason,
        ];
    }

    /**
     * Four public storefront keys out of the description JSON. Anything else in it (provider mappings, service ids,
     * supplier prices) is dropped here and never reaches a result.
     *
     * @return array<string,string>
     */
    private function publicMeta(Product $product): array
    {
        $meta = json_decode((string) $product->description, true);
        $meta = is_array($meta) ? $meta : [];
        $out = [];

        foreach (['category', 'network', 'size', 'validity'] as $key) {
            $value = $meta[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $out[$key] = VendorToolSupport::text($value, 40);
            }
        }

        return $out;
    }
}
