<?php

namespace App\Services\DeveloperApi;

use App\Models\Developer\DeveloperApplication;
use App\Models\NetworkService;
use App\Models\Product;
use App\Models\ResellerProduct;
use App\Support\ServiceAvailability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * THE source of truth for "what may an API application see and buy?".
 *
 * Mirrors the public marketplace rules in CheckoutController::show() — active,
 * non-deleted product, approved seller, resellable base product for reseller
 * listings, open service category — and layers the application's own data
 * boundary on top:
 *
 *   - marketplace application  -> offers from every approved vendor;
 *   - vendor-bound application -> ONLY that vendor's own products and that
 *     vendor's reseller listings, and only once the vendor link is APPROVED.
 *     An unapproved link yields an EMPTY catalog, never the whole marketplace.
 *
 * The boundary is applied inside the queries (not by filtering results), so a
 * vendor-bound application can never retrieve, enumerate or even confirm the
 * existence of another vendor's offer by id.
 *
 * Offer ids:  own_{products.id}   direct sale of a vendor's own product
 *             rsl_{reseller_products.id}   a reseller's listing
 */
class PublicCatalogService
{
    public const PREFIX_OWNED = 'own_';

    public const PREFIX_RESELLER = 'rsl_';

    /**
     * @param  array{network?:?string,service?:?string,vendor?:?string,min_price?:?numeric,max_price?:?numeric,q?:?string,sort?:?string}  $filters
     * @return Collection<int,array<string,mixed>>
     */
    public function offers(DeveloperApplication $app, array $filters = []): Collection
    {
        if ($this->catalogIsBlocked($app)) {
            return collect();
        }

        $open = $this->openCategories();
        if ($open === []) {
            return collect();
        }

        $images = $this->networkImages();

        $owned = $this->ownedQuery($app)->get()->map(fn (Product $p) => $this->ownedOffer($p, $images));
        $reseller = $this->resellerQuery($app)->get()->map(fn (ResellerProduct $r) => $this->resellerOffer($r, $images));

        $offers = $owned->concat($reseller)
            ->filter(fn (array $o) => in_array($o['service'], $open, true) && $o['price'] > 0);

        return $this->applyFilters($offers, $filters)->values();
    }

    /** One visible offer, or null. Uses the same constraints as the listing. */
    public function find(DeveloperApplication $app, string $offerId): ?array
    {
        if ($this->catalogIsBlocked($app)) {
            return null;
        }

        $images = $this->networkImages();

        if (str_starts_with($offerId, self::PREFIX_OWNED) && ctype_digit($id = substr($offerId, strlen(self::PREFIX_OWNED)))) {
            $product = $this->ownedQuery($app)->whereKey((int) $id)->first();
            $offer = $product ? $this->ownedOffer($product, $images) : null;
        } elseif (str_starts_with($offerId, self::PREFIX_RESELLER) && ctype_digit($id = substr($offerId, strlen(self::PREFIX_RESELLER)))) {
            $listing = $this->resellerQuery($app)->whereKey((int) $id)->first();
            $offer = $listing ? $this->resellerOffer($listing, $images) : null;
        } else {
            return null;
        }

        if (! $offer || $offer['price'] <= 0 || ! in_array($offer['service'], $this->openCategories(), true)) {
            return null;
        }

        return $offer;
    }

    /**
     * The row-level objects behind an offer, for pricing/order creation. Same
     * visibility rules as find(); null when the offer is not purchasable by this app.
     *
     * @return array{offer:array<string,mixed>,product:Product,reseller:?ResellerProduct,vendor_id:int}|null
     */
    public function resolveForPurchase(DeveloperApplication $app, string $offerId): ?array
    {
        $offer = $this->find($app, $offerId);
        if (! $offer) {
            return null;
        }

        if ($offer['type'] === 'reseller') {
            $listing = $this->resellerQuery($app)->whereKey((int) substr($offerId, strlen(self::PREFIX_RESELLER)))->first();

            return $listing ? ['offer' => $offer, 'product' => $listing->product, 'reseller' => $listing, 'vendor_id' => (int) $listing->reseller_vendor_id] : null;
        }

        $product = $this->ownedQuery($app)->whereKey((int) substr($offerId, strlen(self::PREFIX_OWNED)))->first();

        return $product ? ['offer' => $offer, 'product' => $product, 'reseller' => null, 'vendor_id' => (int) $product->vendor_id] : null;
    }

    /** @return list<array{code:string,name:string,image_url:?string,offers:int}> */
    public function networks(DeveloperApplication $app): array
    {
        $out = [];
        foreach ($this->offers($app) as $offer) {
            if (! $offer['network']) {
                continue;
            }
            $code = strtoupper($offer['network']);
            $out[$code] ??= ['code' => $code, 'name' => $offer['network'], 'image_url' => $offer['network_image_url'], 'offers' => 0];
            $out[$code]['offers']++;
        }
        ksort($out);

        return array_values($out);
    }

    /** @return list<array{code:string,name:string,description:string,available:bool}> */
    public function services(): array
    {
        $labels = config('storefront.categories', []);
        $exposed = (array) config('developer_api.exposed_categories', []);
        $out = [];

        foreach ($exposed as $code) {
            $out[] = [
                'code' => $code,
                'name' => $labels[$code]['label'] ?? ucfirst($code),
                'description' => $labels[$code]['description'] ?? '',
                'available' => ServiceAvailability::isOpen($code),
            ];
        }

        return $out;
    }

    // ---------------------------------------------------------------- queries

    private function catalogIsBlocked(DeveloperApplication $app): bool
    {
        return $app->isVendorBound() && ! $app->vendorScopeIsActive();
    }

    /** @return Builder<Product> */
    private function ownedQuery(DeveloperApplication $app): Builder
    {
        $q = Product::query()
            ->where('is_active', true)
            ->whereHas('vendor', fn ($v) => $v->where('is_approved', true))
            ->with('vendor:id,name,vendor_code');

        if ($app->vendorScopeIsActive()) {
            $q->where('vendor_id', $app->vendor_id);
        }

        return $q;
    }

    /** @return Builder<ResellerProduct> */
    private function resellerQuery(DeveloperApplication $app): Builder
    {
        $q = ResellerProduct::query()
            ->where('is_active', true)
            ->whereHas('product', fn ($p) => $p->where('is_active', true)->where('is_resellable', true))
            ->whereHas('resellerVendor', fn ($v) => $v->where('is_approved', true))
            ->with(['product', 'resellerVendor:id,name,vendor_code']);

        if ($app->vendorScopeIsActive()) {
            $q->where('reseller_vendor_id', $app->vendor_id);
        }

        return $q;
    }

    // ------------------------------------------------------------ transforms

    /** @return list<string> exposed categories that an admin has not closed */
    private function openCategories(): array
    {
        return array_values(array_filter(
            (array) config('developer_api.exposed_categories', []),
            fn (string $c) => ServiceAvailability::isOpen($c)
        ));
    }

    /** @return array<string,?string> lowercase network name => image url */
    private function networkImages(): array
    {
        return NetworkService::active()->whereNotNull('image_path')->get()
            ->mapWithKeys(fn ($s) => [strtolower($s->name) => $s->image_url])->all();
    }

    private function ownedOffer(Product $p, array $images): array
    {
        return $this->shape(
            id: self::PREFIX_OWNED.$p->id,
            type: 'direct',
            product: $p,
            price: (float) $p->price,
            vendor: $p->vendor,
            images: $images,
        );
    }

    private function resellerOffer(ResellerProduct $r, array $images): array
    {
        return $this->shape(
            id: self::PREFIX_RESELLER.$r->id,
            type: 'reseller',
            product: $r->product,
            // Same authoritative derivation OrderPricingSnapshot uses: base + markup.
            price: (float) \App\Support\Money::sum($r->base_price, $r->markup_price),
            vendor: $r->resellerVendor,
            images: $images,
        );
    }

    private function shape(string $id, string $type, Product $product, float $price, $vendor, array $images): array
    {
        $meta = $product->decoded_description;
        $network = isset($meta['network']) && is_string($meta['network']) ? $meta['network'] : null;

        return [
            'id' => $id,
            'type' => $type,
            'name' => $product->name,
            'service' => (string) ($meta['category'] ?? config('storefront.default_category', 'data')),
            'network' => $network,
            'network_image_url' => $network ? ($images[strtolower($network)] ?? null) : null,
            'size' => $meta['size'] ?? null,
            'validity' => $meta['validity'] ?? null,
            'description' => $meta['notes'] ?? $meta['description'] ?? null,
            'price' => round($price, 2),
            'currency' => \App\Services\Payments\OrderPricingSnapshot::defaultCurrency(),
            'available' => true,
            'seller' => ['code' => $vendor->vendor_code, 'name' => $vendor->name],
        ];
    }

    private function applyFilters(Collection $offers, array $f): Collection
    {
        if (! empty($f['network'])) {
            $n = strtolower((string) $f['network']);
            $offers = $offers->filter(fn ($o) => strtolower((string) $o['network']) === $n);
        }
        if (! empty($f['service'])) {
            $offers = $offers->filter(fn ($o) => $o['service'] === $f['service']);
        }
        if (! empty($f['vendor'])) {
            $offers = $offers->filter(fn ($o) => strcasecmp($o['seller']['code'], (string) $f['vendor']) === 0);
        }
        if (isset($f['min_price']) && $f['min_price'] !== '') {
            $offers = $offers->filter(fn ($o) => $o['price'] >= (float) $f['min_price']);
        }
        if (isset($f['max_price']) && $f['max_price'] !== '') {
            $offers = $offers->filter(fn ($o) => $o['price'] <= (float) $f['max_price']);
        }
        if (! empty($f['q'])) {
            $needle = strtolower((string) $f['q']);
            $offers = $offers->filter(fn ($o) => str_contains(strtolower($o['name'].' '.$o['size'].' '.$o['network']), $needle));
        }

        return match ($f['sort'] ?? 'price') {
            '-price' => $offers->sortByDesc('price'),
            'name' => $offers->sortBy(fn ($o) => strtolower($o['name'])),
            default => $offers->sortBy('price'),
        };
    }
}
