<?php

namespace App\Services\Catalogue;

use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\Vendor;
use App\Support\Money;
use App\Support\PlatformServiceCatalog;
use App\Support\PlatformServiceVendor;
use App\Support\ServiceAvailability;
use App\Support\SupersededCategories;
use Illuminate\Database\Eloquent\Builder;

/**
 * READ-ONLY view of the OFFICIAL public XTRA4U catalogue: exactly what a visitor sees on the platform service pages
 * (/services/data-bundles, /services/shop, /services/result-checkers, /services/afa-registration) - nothing more.
 *
 * "Publicly available" is decided by the same rules those pages use, and every rule that can be a SQL condition IS one:
 *   - the category must be open (ServiceAvailability), and an admin-assigned vendor must resolve and still be approved
 *     (PlatformServiceVendor::resolveFresh) - a missing assignment never falls back to some other vendor;
 *   - an owned product: vendor_id = that vendor AND is_active (soft-deleted rows are excluded by the model scope);
 *   - a reseller listing: reseller_vendor_id = that vendor AND the listing is active AND its source product is active
 *     AND resellable (and not soft-deleted);
 *   - a result checker: the existing PlatformServiceCatalog::resultCheckersForVendor() rule, reused unchanged.
 *
 * PRICES are never computed here. An owned product's price is `products.price`; a listing's is its stored
 * `selling_price` (the model derives it on save); a result checker's is the one the page itself shows. These are the
 * same fields the pages read. The checkout page remains the authority for the amount actually charged.
 *
 * The product `description` column holds JSON that also carries private provider mappings. It is decoded here and ONLY
 * a fixed whitelist of storefront keys (category, service/network, size, validity, notes) is ever read out of it.
 *
 * Vendor identity, internal ids of vendors, costs, margins, base/markup prices, provider mappings, inventory and
 * everything about orders/payments/wallets are not read at all.
 */
class PublicCatalogueReadService
{
    public const CURRENCY = 'GHS';

    /** Categories backed by `products` rows (the category lives in the description JSON). */
    public const PRODUCT_CATEGORIES = ['data', 'shop'];

    /** Every category the official pages serve (utility bills is a separate global service, see services()). */
    public const CATEGORIES = ['data', 'shop', 'results', 'afa'];

    /**
     * Most rows ONE source (owned products / reseller listings) can contribute to one category's result. The category and
     * the search words are filtered IN SQL (see categoryCondition()), so these are rows that already belong to the
     * category and match the words: a catalogue with thousands of products in other categories cannot push a match out of
     * the window. If more candidates than this exist the result says so (`incomplete`) instead of pretending otherwise.
     */
    public const SCAN_LIMIT = 400;

    public const SORTS = ['price_asc', 'price_desc', 'name'];

    /** ref prefix => what it identifies. These are the package ids the public pages already use. */
    private const REF_PATTERN = '/^(product|reseller|result_checker)_([1-9][0-9]{0,9})$/D';

    private const PAGE_ROUTES = [
        'data' => 'services.data-bundles',
        'shop' => 'services.shop',
        'results' => 'services.result-checkers',
        'afa' => 'services.afa-registration',
        'utility_bills' => 'services.utility-bills',
    ];

    // ------------------------------------------------------------------------------------------------ services

    /**
     * The public service categories with their current availability. availability is one of:
     *   available   - open and at least one offering can be bought right now
     *   closed      - an administrator has closed the category
     *   unavailable - open, but nothing can be bought at the moment (no vendor assigned / nothing listed)
     *   see_page    - availability is only known live on the service's own page (Utility Bills)
     *
     * @return list<array{category:string,label:string,description:string,availability:string,page_path:string}>
     */
    public function services(): array
    {
        $out = [];

        foreach (self::CATEGORIES as $category) {
            $availability = match (true) {
                ServiceAvailability::isClosedFresh($category) => 'closed',
                $category === 'afa' => $this->afaOffered() ? 'available' : 'unavailable',
                default => $this->search(null, $category, 'name', 1, 1)['items'] === [] ? 'unavailable' : 'available',
            };

            $out[] = $this->descriptor($category, $availability);
        }

        $out[] = [
            'category' => 'utility_bills',
            'label' => SupersededCategories::UTILITY_BILLS_LABEL,
            'description' => SupersededCategories::UTILITY_BILLS_DESCRIPTION,
            'availability' => 'see_page',
            'page_path' => $this->pagePath('utility_bills'),
        ];

        return $out;
    }

    /** @return array{category:string,label:string,description:string,availability:string,page_path:string} */
    private function descriptor(string $category, string $availability): array
    {
        $config = (array) config("storefront.categories.{$category}", []);

        return [
            'category' => $category,
            'label' => (string) ($config['public_label'] ?? $config['label'] ?? ucfirst($category)),
            'description' => (string) ($config['public_description'] ?? $config['description'] ?? ''),
            'availability' => $availability,
            'page_path' => $this->pagePath($category),
        ];
    }

    public function pagePath(string $category): string
    {
        return route(self::PAGE_ROUTES[$category], [], false);
    }

    /**
     * Label and path of each public service page, keyed by category: the only link targets the assistant UI may show.
     *
     * @return array<string,array{label:string,path:string}>
     */
    public function linkTargets(): array
    {
        $targets = [];

        foreach (array_keys(self::PAGE_ROUTES) as $category) {
            $config = (array) config("storefront.categories.{$category}", []);
            $label = $category === 'utility_bills'
                ? SupersededCategories::UTILITY_BILLS_LABEL
                : (string) ($config['public_label'] ?? $config['label'] ?? ucfirst($category));

            $targets[$category] = ['label' => $label, 'path' => $this->pagePath($category)];
        }

        return $targets;
    }

    // -------------------------------------------------------------------------------------------------- search

    /**
     * @param  ?string  $text  free text, matched (all words) against name / service / size / validity only
     * @param  ?string  $category  one of PRODUCT_CATEGORIES + results, or null for all three
     * @return array{items:list<array<string,mixed>>,has_more:bool,incomplete:bool}
     */
    public function search(?string $text, ?string $category, string $sort, int $page, int $limit): array
    {
        $terms = $this->terms($text);

        // Words were asked for but none survived cleaning (only wildcard/control characters): nothing can match.
        if ($text !== null && trim($text) !== '' && $terms === []) {
            return ['items' => [], 'has_more' => false, 'incomplete' => false];
        }

        $categories = $category !== null && in_array($category, [...self::PRODUCT_CATEGORIES, 'results'], true)
            ? [$category]
            : [...self::PRODUCT_CATEGORIES, 'results'];

        $items = [];
        $incomplete = false;

        foreach ($categories as $cat) {
            $vendor = $this->openVendor($cat);
            if ($vendor === null) {
                continue;
            }

            if ($cat === 'results') {
                array_push($items, ...$this->resultCheckerItems($vendor, $terms));

                continue;
            }

            [$found, $cut] = $this->productItems($cat, $vendor, $terms);
            array_push($items, ...$found);
            $incomplete = $incomplete || $cut;
        }

        usort($items, function (array $a, array $b) use ($sort) {
            return match ($sort) {
                'price_desc' => [$b['price_minor'], $a['name'], $a['ref']] <=> [$a['price_minor'], $b['name'], $b['ref']],
                'name' => [$a['name'], $a['price_minor'], $a['ref']] <=> [$b['name'], $b['price_minor'], $b['ref']],
                default => [$a['price_minor'], $a['name'], $a['ref']] <=> [$b['price_minor'], $b['name'], $b['ref']],
            };
        });

        $offset = ($page - 1) * $limit;

        return [
            'items' => array_slice($items, $offset, $limit),
            'has_more' => count($items) > $offset + $limit,
            'incomplete' => $incomplete,
        ];
    }

    // ----------------------------------------------------------------------------------------------- details

    /**
     * One publicly available offering by its public ref ("product_12", "reseller_5", "result_checker_3"), or null.
     * "Does not exist", "inactive", "not resellable", "category closed", "not on an official page" and "malformed ref"
     * are indistinguishable to the caller.
     *
     * @return ?array<string,mixed>
     */
    public function find(string $ref): ?array
    {
        if (preg_match(self::REF_PATTERN, $ref, $m) !== 1) {
            return null;
        }

        $id = (int) $m[2];
        $vendors = [];
        foreach ([...self::PRODUCT_CATEGORIES, 'results'] as $cat) {
            $vendor = $this->openVendor($cat);
            if ($vendor !== null) {
                $vendors[$cat] = $vendor;
            }
        }

        $productVendorIds = array_values(array_map(fn (Vendor $v) => $v->id, array_intersect_key($vendors, array_flip(self::PRODUCT_CATEGORIES))));

        switch ($m[1]) {
            case 'product':
                $product = Product::query()
                    ->whereKey($id)->where('is_active', true)->whereIn('vendor_id', $productVendorIds)
                    ->first(['id', 'vendor_id', 'name', 'description', 'price']);

                return $product === null ? null : $this->ownedItem($product, $vendors, true);

            case 'reseller':
                $listing = ResellerProduct::query()
                    ->whereKey($id)->where('is_active', true)->whereIn('reseller_vendor_id', $productVendorIds)
                    ->whereHas('product', fn (Builder $p) => $p->where('is_active', true)->where('is_resellable', true))
                    ->with('product:id,name,description')
                    ->first(['id', 'product_id', 'reseller_vendor_id', 'selling_price']);

                return $listing === null ? null : $this->listingItem($listing, $vendors, true);

            default:
                if (! isset($vendors['results'])) {
                    return null;
                }
                foreach ($this->resultCheckerItems($vendors['results'], []) as $item) {
                    if ($item['ref'] === $ref) {
                        return $item;
                    }
                }

                return null;
        }
    }

    // ----------------------------------------------------------------------------------------------- internals

    /**
     * The category's assigned vendor, but only when the category is open and the vendor is approved.
     *
     * Settings are read with the *Fresh variants, straight from the `settings` table. The cached readers
     * (ServiceAvailability::isClosed / PlatformServiceVendor::resolve) go through Cache::remember, which on the database
     * cache store WRITES to the `cache` table on a miss - and on EVERY call for a setting that has no row. This service
     * runs inside the AI tools' SELECT-only ReadOnlyGuard, which rightly refuses that write, so using them here made the
     * catalogue tools fail with `read_only_violation` in production. The guard is unchanged; the read simply does not
     * need a cache. The meaning is identical (no row = open; a vendor only while approved), see ColdDatabaseCacheTest.
     */
    private function openVendor(string $category): ?Vendor
    {
        if (ServiceAvailability::isClosedFresh($category)) {
            return null;
        }

        return PlatformServiceVendor::resolveFresh($category);
    }

    private function afaOffered(): bool
    {
        $vendor = $this->openVendor('afa');

        return $vendor !== null && (bool) $vendor->offersAfaRegistration();
    }

    /** @return list<string> lowercase words, at most 5, with LIKE wildcards removed (works the same on every engine) */
    private function terms(?string $text): array
    {
        if ($text === null) {
            return [];
        }

        $text = mb_strtolower((string) preg_replace('/[%_\\\\\p{C}]+/u', ' ', $text));
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice(array_values(array_unique($words)), 0, 5);
    }

    /**
     * @param  list<string>  $terms
     * @return array{0:list<array<string,mixed>>,1:bool} items, and whether the scan limit cut the candidates short
     */
    private function productItems(string $category, Vendor $vendor, array $terms): array
    {
        $owned = Product::query()
            ->where('vendor_id', $vendor->id)->where('is_active', true)
            ->where(fn (Builder $q) => $this->categoryCondition($q, $category))
            ->where(fn (Builder $q) => $this->termsOnProduct($q, $terms))
            ->orderBy('id')->limit(self::SCAN_LIMIT + 1)
            ->get(['id', 'vendor_id', 'name', 'description', 'price']);

        $listings = ResellerProduct::query()
            ->where('reseller_vendor_id', $vendor->id)->where('is_active', true)
            ->whereHas('product', fn (Builder $p) => $p->where('is_active', true)->where('is_resellable', true)
                ->where(fn (Builder $q) => $this->categoryCondition($q, $category))
                ->where(fn (Builder $q) => $this->termsOnProduct($q, $terms)))
            ->with('product:id,name,description')
            ->orderBy('id')->limit(self::SCAN_LIMIT + 1)
            ->get(['id', 'product_id', 'reseller_vendor_id', 'selling_price']);

        $cut = $owned->count() > self::SCAN_LIMIT || $listings->count() > self::SCAN_LIMIT;

        $items = [];
        foreach ($owned->take(self::SCAN_LIMIT) as $product) {
            $item = $this->ownedItem($product, [$category => $vendor], false);
            if ($item !== null && $this->matches($item, $terms)) {
                $items[] = $item;
            }
        }
        foreach ($listings->take(self::SCAN_LIMIT) as $listing) {
            $item = $this->listingItem($listing, [$category => $vendor], false);
            if ($item !== null && $this->matches($item, $terms)) {
                $items[] = $item;
            }
        }

        return [array_values(array_filter($items, fn (array $i) => $i['category'] === $category)), $cut];
    }

    /**
     * SQL narrowing for the search words: each word must occur (case-insensitively) in one of the PUBLIC fields the PHP
     * check reads - the product name, or the description JSON's service / network / size / validity. The exact match is
     * still re-checked in PHP on those same fields, so this can only ever drop rows PHP would also have dropped.
     *
     * @param  list<string>  $terms
     */
    private function termsOnProduct(Builder $q, array $terms): void
    {
        $dialect = $this->dialect($q);

        foreach ($terms as $term) {
            $q->where(function (Builder $w) use ($term, $dialect) {
                $w->whereRaw('LOWER(products.name) LIKE ?', ["%{$term}%"]);

                if ($dialect === null) {
                    // No JSON functions on this engine: fall back to the raw description text (a superset, never fewer rows).
                    $w->orWhereRaw('LOWER(products.description) LIKE ?', ["%{$term}%"]);

                    return;
                }

                foreach (['service', 'network', 'size', 'validity'] as $key) {
                    $w->orWhereRaw('LOWER('.$this->jsonText($dialect, $key).') LIKE ?', ["%{$term}%"]);
                }
            });
        }
    }

    /**
     * Keep only products whose STOREFRONT category (see categoryOf()) is `$category`, decided by the database.
     *
     * The category lives in the description column as JSON, and the public pages treat these cases as the default
     * category: no description, a description that is not valid JSON (legacy plain text), JSON that is not an object, no
     * `category` key, a `category` that is not a string, and an empty-string `category`. The expression below encodes
     * exactly that, nested in CASE branches so a malformed document is never handed to a JSON function.
     *
     * On an engine with no supported JSON functions nothing is filtered here and PHP does all of it; the scan window
     * then counts rows of every category, which the `incomplete` flag reports honestly.
     *
     * String comparison in SQL may be case-insensitive (collation); PHP's strict check in ownedItem()/listingItem()
     * remains the authority, so a row like {"category":"DATA"} is dropped there exactly as before.
     */
    private function categoryCondition(Builder $q, string $category): void
    {
        $dialect = $this->dialect($q);

        if ($dialect === null) {
            return;
        }

        $default = (string) config('storefront.default_category', 'data');
        $doc = 'products.description';
        $path = "'\$.category'";

        [$valid, $isString, $extract] = $dialect === 'sqlite'
            ? ["json_valid({$doc})", "json_type({$doc}, {$path}) = 'text'", "json_extract({$doc}, {$path})"]
            : ["JSON_VALID({$doc}) = 1", "JSON_TYPE(JSON_EXTRACT({$doc}, {$path})) = 'STRING'", "JSON_UNQUOTE(JSON_EXTRACT({$doc}, {$path}))"];

        $q->whereRaw(
            "(CASE WHEN {$valid} THEN (CASE WHEN {$isString} AND {$extract} <> '' THEN {$extract} ELSE ? END) ELSE ? END) = ?",
            [$default, $default, $category]
        );
    }

    /** @return ?string 'sqlite' | 'mysql' (MariaDB and MySQL) | null when the engine has no JSON support we rely on */
    private function dialect(Builder $q): ?string
    {
        return match ($q->getQuery()->getConnection()->getDriverName()) {
            'sqlite' => 'sqlite',
            'mysql', 'mariadb' => 'mysql',
            default => null,
        };
    }

    /** SQL for "the string value of $.key in the description JSON, or NULL" (never touches malformed JSON). */
    private function jsonText(string $dialect, string $key): string
    {
        $doc = 'products.description';
        $path = "'\$.{$key}'";

        return $dialect === 'sqlite'
            ? "CASE WHEN json_valid({$doc}) THEN (CASE WHEN json_type({$doc}, {$path}) = 'text' THEN json_extract({$doc}, {$path}) END) END"
            : "CASE WHEN JSON_VALID({$doc}) = 1 THEN (CASE WHEN JSON_TYPE(JSON_EXTRACT({$doc}, {$path})) = 'STRING' THEN JSON_UNQUOTE(JSON_EXTRACT({$doc}, {$path})) END) END";
    }

    /** @param list<string> $terms every term must appear in the item's public text */
    private function matches(array $item, array $terms): bool
    {
        if ($terms === []) {
            return true;
        }

        $haystack = mb_strtolower(implode(' ', array_filter([$item['name'], $item['service'], $item['size'], $item['validity']])));

        foreach ($terms as $term) {
            if (! str_contains($haystack, $term)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,Vendor>  $vendors  category => the open, assigned vendor
     * @return ?array<string,mixed>
     */
    private function ownedItem(Product $product, array $vendors, bool $withNotes): ?array
    {
        [$meta, $description] = $this->decode($product->description);
        $category = $this->categoryOf($meta);

        // The product must belong to the vendor assigned to ITS category (checked again here for find()).
        if (! in_array($category, self::PRODUCT_CATEGORIES, true) || ! isset($vendors[$category]) || $vendors[$category]->id !== $product->vendor_id) {
            return null;
        }

        return $this->item('product_'.$product->id, (string) $product->name, $category, $meta, $description, $product->price, $withNotes);
    }

    /**
     * @param  array<string,Vendor>  $vendors
     * @return ?array<string,mixed>
     */
    private function listingItem(ResellerProduct $listing, array $vendors, bool $withNotes): ?array
    {
        $source = $listing->product;
        if ($source === null) {
            return null;
        }

        [$meta, $description] = $this->decode($source->description);
        $category = $this->categoryOf($meta);

        if (! in_array($category, self::PRODUCT_CATEGORIES, true) || ! isset($vendors[$category]) || $vendors[$category]->id !== $listing->reseller_vendor_id) {
            return null;
        }

        return $this->item('reseller_'.$listing->id, (string) $source->name, $category, $meta, $description, $listing->selling_price, $withNotes);
    }

    /**
     * @param  list<string>  $terms
     * @return list<array<string,mixed>>
     */
    private function resultCheckerItems(Vendor $vendor, array $terms): array
    {
        $items = [];

        foreach (PlatformServiceCatalog::resultCheckersForVendor($vendor) as $service) {
            $package = $service['packages'][0] ?? null;
            if (! is_array($package) || ! is_string($package['id'] ?? null)) {
                continue;
            }

            $item = [
                'ref' => $package['id'],
                'name' => (string) ($package['name'] ?? $service['name'] ?? ''),
                'category' => 'results',
                'service' => (string) ($service['name'] ?? ''),
                'size' => null,
                'validity' => null,
                'notes' => is_string($package['notes'] ?? null) ? $package['notes'] : null,
                'price' => $this->money($package['price'] ?? 0),
                'price_minor' => $this->minor($package['price'] ?? 0),
            ];

            if ($this->matches($item, $terms)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    private function item(string $ref, string $name, string $category, array $meta, ?string $plainDescription, mixed $price, bool $withNotes): array
    {
        $string = fn (string $key) => isset($meta[$key]) && is_string($meta[$key]) && trim($meta[$key]) !== '' ? $meta[$key] : null;

        $notes = null;
        if ($withNotes) {
            $notes = $string('notes') ?? $plainDescription;
        }

        return [
            'ref' => $ref,
            'name' => $name,
            'category' => $category,
            'service' => $string('service') ?? $string('network') ?? $name,
            'size' => $string('size'),
            'validity' => $string('validity'),
            'notes' => $notes,
            'price' => $this->money($price),
            'price_minor' => $this->minor($price),
        ];
    }

    /**
     * @return array{0:array<string,mixed>,1:?string} the decoded metadata (array), and the description when it is plain
     *                                                text rather than JSON (legacy products). A JSON description that is
     *                                                not an object is ignored entirely.
     */
    private function decode(?string $description): array
    {
        if ($description === null || trim($description) === '') {
            return [[], null];
        }

        $decoded = json_decode($description, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [[], $description];
        }

        return [is_array($decoded) ? $decoded : [], null];
    }

    /** @param array<string,mixed> $meta */
    private function categoryOf(array $meta): string
    {
        $default = (string) config('storefront.default_category', 'data');

        return isset($meta['category']) && is_string($meta['category']) && $meta['category'] !== '' ? $meta['category'] : $default;
    }

    private function money(mixed $value): string
    {
        return number_format(round((float) $value, 2), 2, '.', '');
    }

    private function minor(mixed $value): int
    {
        return Money::minor($value);
    }
}
