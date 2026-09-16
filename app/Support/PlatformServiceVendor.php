<?php

namespace App\Support;

use App\Models\Product;
use App\Models\Setting;
use App\Models\Vendor;
use App\Models\VendorResultCheckerSetting;
use Illuminate\Support\Collection;

/**
 * Admin-controlled mapping of each platform storefront category (data, ecg,
 * shop, results, afa — see config/storefront.php) to the single vendor whose
 * catalog powers the official XTRA4U "/services/{category}" page for that
 * category.
 *
 * Backed by the generic `settings` table (see App\Support\ServiceAvailability
 * for the sibling feature using the same storage pattern), so no migration is
 * needed. Unlike App\Support\MainStore — which silently falls back to any
 * approved vendor so a vendor-agnostic link never 404s — this class NEVER
 * substitutes a different vendor than the one an admin configured. Silently
 * swapping in a random vendor would put that vendor's products behind an
 * official XTRA4U service card, which is the one thing this feature must
 * never do. Callers get null and are expected to render an "unavailable"
 * state (see App\Support\ServiceAvailability's services-closed page).
 */
class PlatformServiceVendor
{
    /** Settings group used for every key written by this feature. */
    public const GROUP = 'platform_service_vendors';

    /**
     * Category keys this feature manages, taken from the storefront config so
     * it stays in sync with the categories the marketplace actually offers.
     *
     * @return array<int,string>
     */
    public static function categories(): array
    {
        return array_keys(config('storefront.categories', []));
    }

    public static function key(string $category): string
    {
        return 'platform_service_vendor.'.$category;
    }

    /**
     * The admin-configured vendor ID for a category, or null if unset.
     * Does not validate the vendor still exists/is approved — see resolve().
     */
    public static function vendorIdFor(string $category): ?int
    {
        $value = Setting::get(self::key($category));

        return ($value !== null && $value !== '') ? (int) $value : null;
    }

    public static function setVendorFor(string $category, ?int $vendorId): void
    {
        Setting::set(self::key($category), $vendorId ? (string) $vendorId : '', self::GROUP);
    }

    /**
     * The admin-configured vendor ID for every managed category.
     *
     * @return array<string,int|null> category => vendor_id
     */
    public static function assignments(): array
    {
        $assignments = [];
        foreach (self::categories() as $category) {
            $assignments[$category] = self::vendorIdFor($category);
        }

        return $assignments;
    }

    /**
     * Resolve the vendor configured for a category, validated as usable:
     * exists and is approved. Does NOT check whether the vendor currently has
     * active products/offerings in that category — that check is
     * category-specific (a Data query differs from an AFA field check) and
     * belongs to the customer-facing controller that already knows how to
     * ask that question for its own category, so it isn't duplicated here.
     *
     * Returns null (never a substitute vendor) when unconfigured, deleted,
     * or unapproved.
     */
    public static function resolve(string $category): ?Vendor
    {
        $vendorId = self::vendorIdFor($category);

        if (! $vendorId) {
            return null;
        }

        $vendor = Vendor::find($vendorId);

        if (! $vendor || ! $vendor->is_approved) {
            return null;
        }

        return $vendor;
    }

    /**
     * Approved vendors eligible for the admin selector, annotated with a
     * best-effort signal of whether they already offer the category — shown
     * to guide the admin's choice, never used to hide a vendor from the list.
     * Category is inferred from JSON product metadata (no indexed column
     * exists for it — see Product::description), so this is a heuristic, not
     * a hard constraint; hard-filtering on it could wrongly hide an otherwise
     * valid vendor from selection.
     *
     * @return Collection<int,array{vendor:Vendor,eligible:bool,hint:?string}>
     */
    public static function eligibleVendors(string $category): Collection
    {
        $vendors = Vendor::where('is_approved', true)->orderBy('name')->get();

        return match ($category) {
            'results' => self::annotateForResultsChecker($vendors),
            'afa' => self::annotateForAfa($vendors),
            default => self::annotateForProductCategory($vendors, $category),
        };
    }

    private static function annotateForProductCategory(Collection $vendors, string $category): Collection
    {
        // One query for all vendors' active products, decoded once.
        $countsByVendor = Product::where('is_active', true)
            ->get(['vendor_id', 'description'])
            ->filter(fn (Product $product) => ServiceAvailability::categoryForProduct($product) === $category)
            ->countBy('vendor_id');

        return $vendors->map(function (Vendor $vendor) use ($countsByVendor) {
            $count = $countsByVendor->get($vendor->id, 0);

            return [
                'vendor' => $vendor,
                'eligible' => $count > 0,
                'hint' => $count > 0 ? "{$count} active product".($count === 1 ? '' : 's') : null,
            ];
        });
    }

    private static function annotateForResultsChecker(Collection $vendors): Collection
    {
        $activeVendorIds = VendorResultCheckerSetting::where('is_active', true)
            ->pluck('vendor_id')
            ->unique();

        return $vendors->map(fn (Vendor $vendor) => [
            'vendor' => $vendor,
            'eligible' => $activeVendorIds->contains($vendor->id),
            'hint' => $activeVendorIds->contains($vendor->id) ? 'Offers result checkers' : null,
        ]);
    }

    private static function annotateForAfa(Collection $vendors): Collection
    {
        return $vendors->map(function (Vendor $vendor) {
            $eligible = $vendor->offersAfaRegistration();

            return [
                'vendor' => $vendor,
                'eligible' => $eligible,
                'hint' => $eligible ? 'AFA registration enabled' : null,
            ];
        });
    }
}
