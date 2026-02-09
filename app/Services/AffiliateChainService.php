<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ResellerProduct;
use App\Models\Vendor;
use Illuminate\Support\Facades\Log;

class AffiliateChainService
{
    public const DEFAULT_MAX_DEPTH = 7;

    /**
     * Resolve the immediate parent affiliate's resell price for a given product.
     * - If the parent owns the product, return the owner's base/min price.
     * - If the parent is reselling, return the parent listing's selling_price (base + markup).
     * - If no parent affiliate or parent does not offer the product, return a failure reason.
     */
    public function resolveImmediateParentResellPricing(Product $product, Vendor $childVendor): array
    {
        $parentVendorId = (int) $childVendor->affiliate_vendor_id;
        if ($parentVendorId <= 0) {
            return ['ok' => false, 'reason' => 'missing_affiliate_parent'];
        }

        $parentVendor = Vendor::find($parentVendorId);
        if (! $parentVendor) {
            return ['ok' => false, 'reason' => 'missing_parent_vendor'];
        }

        $parentListing = ResellerProduct::query()
            ->where('product_id', $product->id)
            ->where('reseller_vendor_id', $parentVendor->id)
            ->where('is_active', true)
            ->first();

        $parentOwnsProduct = ((int) $product->vendor_id === (int) $parentVendor->id);

        if (! $parentOwnsProduct && ! $parentListing) {
            return ['ok' => false, 'reason' => 'parent_not_offering_product'];
        }

        $basePrice = $parentListing
            ? (float) $parentListing->selling_price // immediate parent selling price (base + markup)
            : (float) ($product->min_base_price ?? $product->price); // owner base price

        $ownerVendorId = $parentListing
            ? (int) $parentListing->owner_vendor_id
            : (int) $product->vendor_id;

        $sourceResellerProductId = $parentListing ? (int) $parentListing->id : null;

        return [
            'ok' => true,
            'base_price' => $basePrice,
            'owner_vendor_id' => $ownerVendorId,
            'source_reseller_product_id' => $sourceResellerProductId,
            'parent_listing' => $parentListing,
            'parent_vendor' => $parentVendor,
            'parent_owns_product' => $parentOwnsProduct,
        ];
    }

    /**
     * Build an upline chain (vendor -> parent -> ... -> root) using affiliate_vendor_id.
     * Returns an array of Vendor models starting with $vendor and ending with the root.
     */
    public function buildVendorUpline(Vendor $vendor, int $maxDepth = self::DEFAULT_MAX_DEPTH): array
    {
        $chain = [];
        $visited = [];

        $cursor = $vendor;
        for ($i = 0; $i < $maxDepth; $i++) {
            if (! $cursor instanceof Vendor) {
                break;
            }

            if (isset($visited[$cursor->id])) {
                Log::warning('Affiliate vendor upline loop detected', [
                    'vendor_id' => $vendor->id,
                    'loop_at_vendor_id' => $cursor->id,
                ]);
                break;
            }

            $visited[$cursor->id] = true;
            $chain[] = $cursor;

            $parentId = $cursor->affiliate_vendor_id;
            if (! $parentId) {
                break;
            }

            $cursor = Vendor::find($parentId);
            if (! $cursor) {
                break;
            }
        }

        return $chain;
    }

    /**
     * Build an upline chain (leaf listing -> upstream listing -> ... -> root listing)
     * using source_reseller_product_id.
     * Returns an array of ResellerProduct models starting with $leaf.
     */
    public function buildResellerProductUpline(ResellerProduct $leaf, int $maxDepth = self::DEFAULT_MAX_DEPTH): array
    {
        $chain = [];
        $visited = [];

        $cursor = $leaf;
        for ($i = 0; $i < $maxDepth; $i++) {
            if (! $cursor instanceof ResellerProduct) {
                break;
            }

            if (isset($visited[$cursor->id])) {
                Log::warning('Reseller product upline loop detected', [
                    'leaf_reseller_product_id' => $leaf->id,
                    'loop_at_reseller_product_id' => $cursor->id,
                ]);
                break;
            }

            $visited[$cursor->id] = true;
            $chain[] = $cursor;

            $sourceId = $cursor->source_reseller_product_id;
            if (! $sourceId) {
                break;
            }

            $cursor = ResellerProduct::find($sourceId);
            if (! $cursor) {
                break;
            }
        }

        return $chain;
    }

    /**
     * Compute payout portions for a reseller product chain.
     * - Root owner gets the root listing's base_price.
     * - Each reseller gets their listing's markup_price.
     * Platform commission is 2% of each portion.
     */
    public function computeResellerProductPayout(ResellerProduct $leaf, int $maxDepth = self::DEFAULT_MAX_DEPTH): array
    {
        $upline = $this->buildResellerProductUpline($leaf, $maxDepth);
        if (empty($upline)) {
            return [
                'ok' => false,
                'reason' => 'empty_chain',
            ];
        }

        $rootListing = end($upline);
        if (! $rootListing instanceof ResellerProduct) {
            return [
                'ok' => false,
                'reason' => 'missing_root',
            ];
        }

        $baseAmount = (float) $rootListing->base_price;
        $ownerVendorId = (int) $rootListing->owner_vendor_id;

        // Reverse so it reads from root listing -> leaf listing
        $downlineListings = array_reverse($upline);

        $snapshot = [];
        $snapshot[] = [
            'vendor_id' => $ownerVendorId,
            'role' => 'owner',
            'amount' => $baseAmount,
        ];

        $lines = [];

        $ownerCommission = round($baseAmount * 0.02, 2);
        $ownerEarning = round($baseAmount - $ownerCommission, 2);

        $lines[] = [
            'vendor_id' => $ownerVendorId,
            'role' => 'owner',
            'amount' => $baseAmount,
            'commission' => $ownerCommission,
            'earning' => $ownerEarning,
        ];

        $platformCommission = $ownerCommission;

        foreach ($downlineListings as $listing) {
            $markup = (float) $listing->markup_price;
            $resellerVendorId = (int) $listing->reseller_vendor_id;

            $snapshot[] = [
                'vendor_id' => $resellerVendorId,
                'role' => 'reseller',
                'markup' => $markup,
                'source_reseller_product_id' => $listing->source_reseller_product_id,
            ];

            // A reseller may have markup 0; keep line for completeness but it won't affect totals.
            $commission = round($markup * 0.02, 2);
            $earning = round($markup - $commission, 2);

            $lines[] = [
                'vendor_id' => $resellerVendorId,
                'role' => 'reseller',
                'amount' => $markup,
                'commission' => $commission,
                'earning' => $earning,
            ];

            $platformCommission = round($platformCommission + $commission, 2);
        }

        $immediateSellerVendorId = (int) $leaf->reseller_vendor_id;
        $immediateSellerMarkup = (float) $leaf->markup_price;
        $immediateSellerCommission = round($immediateSellerMarkup * 0.02, 2);
        $immediateSellerEarning = round($immediateSellerMarkup - $immediateSellerCommission, 2);

        return [
            'ok' => true,
            'owner_vendor_id' => $ownerVendorId,
            'immediate_seller_vendor_id' => $immediateSellerVendorId,
            'base_amount' => $baseAmount,
            'platform_commission' => $platformCommission,
            'owner_earning' => $ownerEarning,
            'immediate_seller_earning' => $immediateSellerEarning,
            'lines' => $lines,
            'snapshot' => $snapshot,
            'depth' => count($downlineListings),
        ];
    }

    /**
     * Return a vendor's effective AFA selling price:
     * - Direct provider: afa_price
     * - Reseller: afa_selling_price
     */
    public function getVendorAfaSellingPrice(Vendor $vendor): ?float
    {
        if ($vendor->afa_enabled && (float) $vendor->afa_price > 0) {
            return (float) $vendor->afa_price;
        }

        if ($vendor->afa_reseller_enabled && (float) $vendor->afa_selling_price > 0) {
            return (float) $vendor->afa_selling_price;
        }

        return null;
    }

    /**
     * Resolve the root AFA provider (first upline vendor that has afa_enabled & afa_price > 0).
     */
    public function resolveRootAfaProviderFromSeller(Vendor $seller, int $maxDepth = self::DEFAULT_MAX_DEPTH): ?Vendor
    {
        $upline = $this->buildVendorUpline($seller, $maxDepth);
        foreach ($upline as $v) {
            if ($v->afa_enabled && (float) $v->afa_price > 0) {
                return $v;
            }
        }

        return null;
    }

    /**
     * Compute payout lines for an AFA sale where $seller is the public-facing vendor.
     * Uses:
     * - Root provider base: root.afa_price
     * - Each reseller markup: vendor.afa_markup (only for vendors in reseller mode)
     */
    public function computeAfaPayoutFromSeller(Vendor $seller, int $maxDepth = self::DEFAULT_MAX_DEPTH): array
    {
        $rootProvider = $this->resolveRootAfaProviderFromSeller($seller, $maxDepth);
        if (! $rootProvider) {
            return ['ok' => false, 'reason' => 'no_root_provider'];
        }

        $baseAmount = (float) $rootProvider->afa_price;
        $ownerVendorId = (int) $rootProvider->id;

        $upline = $this->buildVendorUpline($seller, $maxDepth);

        // Collect reseller markups from seller upwards until (but excluding) root provider.
        $resellerVendors = [];
        foreach ($upline as $v) {
            if ((int) $v->id === (int) $ownerVendorId) {
                break;
            }

            if ($v->afa_reseller_enabled) {
                $resellerVendors[] = $v;
            }
        }

        $snapshot = [];
        $snapshot[] = [
            'vendor_id' => $ownerVendorId,
            'role' => 'owner',
            'amount' => $baseAmount,
        ];

        $lines = [];
        $ownerCommission = round($baseAmount * 0.02, 2);
        $ownerEarning = round($baseAmount - $ownerCommission, 2);
        $platformCommission = $ownerCommission;

        $lines[] = [
            'vendor_id' => $ownerVendorId,
            'role' => 'owner',
            'amount' => $baseAmount,
            'commission' => $ownerCommission,
            'earning' => $ownerEarning,
        ];

        foreach ($resellerVendors as $v) {
            $markup = (float) $v->afa_markup;

            $snapshot[] = [
                'vendor_id' => (int) $v->id,
                'role' => 'reseller',
                'markup' => $markup,
            ];

            $commission = round($markup * 0.02, 2);
            $earning = round($markup - $commission, 2);

            $lines[] = [
                'vendor_id' => (int) $v->id,
                'role' => 'reseller',
                'amount' => $markup,
                'commission' => $commission,
                'earning' => $earning,
            ];

            $platformCommission = round($platformCommission + $commission, 2);
        }

        $immediateSellerMarkup = (float) $seller->afa_markup;
        $immediateSellerCommission = round($immediateSellerMarkup * 0.02, 2);
        $immediateSellerEarning = round($immediateSellerMarkup - $immediateSellerCommission, 2);

        // Expected customer amount: base + sum(markups)
        $expectedAmount = $baseAmount;
        foreach ($resellerVendors as $v) {
            $expectedAmount = round($expectedAmount + (float) $v->afa_markup, 2);
        }

        return [
            'ok' => true,
            'owner_vendor_id' => $ownerVendorId,
            'immediate_seller_vendor_id' => (int) $seller->id,
            'base_amount' => $baseAmount,
            'expected_amount' => $expectedAmount,
            'platform_commission' => $platformCommission,
            'owner_earning' => $ownerEarning,
            'immediate_seller_earning' => $immediateSellerEarning,
            'lines' => $lines,
            'snapshot' => $snapshot,
            'depth' => count($resellerVendors),
        ];
    }
}
