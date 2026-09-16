<?php

namespace App\Services\Payments;

use App\Models\Product;
use App\Models\ResellerProduct;
use App\Support\Money;
use Illuminate\Support\Facades\Log;

/**
 * The single server-side answer to: "how much is THIS order supposed to pay,
 * and how does that amount break down?" — resolved once, at order creation,
 * and then frozen onto the order row forever.
 *
 * PRICING MODEL (authoritative; every caller must obey it):
 *
 *   MAIN VENDOR  owns the product and sets its price. That price is
 *                authoritative — the platform does not override it and does
 *                not impose a minimum on it. A GHS 2 bundle is a legitimate
 *                business decision by its owner.
 *   RESELLER     may add a markup on top, and may change ONLY that markup.
 *                They can never reduce, replace or restate the main vendor's
 *                base price.
 *   CUSTOMER     controls neither. The browser is never a price input.
 *
 *   expected_amount = base_price (main vendor) + markup_price (reseller, 0 for
 *                     a direct sale)
 *
 * IMMUTABILITY: once this snapshot is written onto an order it is the
 * order's financial truth for life. If the main vendor later raises the
 * product price, or the reseller later changes their markup, THAT ORDER still
 * owes exactly what it owed when it was created. Price changes apply to new
 * orders only. Settlement (PaymentService) reads this snapshot and must never
 * re-read the live Product/ResellerProduct rows to price an existing order.
 *
 * Returned arrays are shaped to be spread directly into `Order::create()`.
 */
final class OrderPricingSnapshot
{
    /**
     * Direct sale of a main vendor's own product.
     *
     * The caller is responsible for having already established that this
     * product legitimately belongs to the vendor being bought from — this
     * class prices what it is given, it does not authorize it.
     */
    public static function forOwnedProduct(Product $product, ?string $currency = null): array
    {
        $basePrice = Money::toDecimalString($product->price);

        return self::build($basePrice, '0.00', $currency);
    }

    /**
     * Sale through a reseller's listing.
     *
     * base_price comes from the listing's server-resolved base (which
     * AffiliateChainService derived from the main vendor's product or the
     * upstream parent listing — never from anything the reseller submitted),
     * and markup_price is the reseller's own margin. The expected amount is
     * RECOMPUTED here as base + markup rather than trusting the stored
     * `selling_price` column, so that a corrupted or stale denormalized
     * selling_price can never become the amount a customer is charged.
     */
    public static function forResellerListing(ResellerProduct $resellerProduct, ?string $currency = null): array
    {
        $basePrice = Money::toDecimalString($resellerProduct->base_price);
        $markupPrice = Money::toDecimalString($resellerProduct->markup_price);

        $snapshot = self::build($basePrice, $markupPrice, $currency);

        // Denormalization drift check. ResellerProduct::boot() keeps
        // selling_price = base + markup on every save, so a disagreement here
        // means the row was written around the model (raw query, import, or a
        // direct DB edit). We price from base+markup regardless — this only
        // surfaces the anomaly for an administrator.
        if (! Money::equals($resellerProduct->selling_price, $snapshot['expected_amount'])) {
            Log::warning('OrderPricingSnapshot: reseller selling_price disagrees with base+markup; pricing from base+markup', [
                'reseller_product_id' => $resellerProduct->id,
                'stored_selling_price' => (string) $resellerProduct->selling_price,
                'recomputed_expected_amount' => $snapshot['expected_amount'],
                'base_price' => $basePrice,
                'markup_price' => $markupPrice,
            ]);
        }

        return $snapshot;
    }

    /**
     * Vendor-initiated wallet purchase (dashboard quick-buy).
     *
     * These are charged at the base price the vendor actually sources at plus
     * the platform fee, deliberately ignoring any reseller markup — that
     * existing business rule is preserved exactly. The caller resolves both
     * figures server-side; this method only freezes them in the order's
     * snapshot shape so wallet orders carry the same immutable terms as
     * gateway orders.
     */
    public static function forWalletPurchase(
        int|float|string $basePrice,
        int|float|string $platformFee,
        ?string $currency = null
    ): array {
        $snapshot = self::build(Money::toDecimalString($basePrice), '0.00', $currency);

        // A wallet purchase's payable total is base + platform fee, unlike a
        // customer sale where the fee is taken out of the vendor's earnings.
        $snapshot['expected_amount'] = Money::sum($basePrice, $platformFee);
        $snapshot['platform_commission'] = Money::toDecimalString($platformFee);

        return $snapshot;
    }

    /**
     * Default currency for the platform. Single-currency today (GHS); read
     * from config so it is stated in exactly one place rather than as a
     * literal scattered through the payment pipeline.
     */
    public static function defaultCurrency(): string
    {
        $currency = strtoupper(trim((string) config('payments.currency', 'GHS')));

        return $currency !== '' ? $currency : 'GHS';
    }

    private static function build(string $basePrice, string $markupPrice, ?string $currency): array
    {
        return [
            'base_price' => $basePrice,
            'markup_price' => $markupPrice,
            'expected_amount' => Money::sum($basePrice, $markupPrice),
            'currency' => $currency ? strtoupper($currency) : self::defaultCurrency(),
            'pricing_snapshot_at' => now(),
        ];
    }
}
