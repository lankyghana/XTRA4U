<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class ResellerProduct extends Model
{
    /**
     * `selling_price` remains mass assignable for backwards compatibility but
     * is ALWAYS overwritten on save with base + markup (see booted()), so no
     * caller — trusted or not — can set it to anything else.
     */
    protected $fillable = [
        'product_id',
        'source_reseller_product_id',
        'reseller_vendor_id',
        'owner_vendor_id',
        'base_price',
        'markup_price',
        'selling_price',
        'is_active',
    ];

    protected $casts = [
        'base_price' => 'decimal:2',
        'markup_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    // Original product
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // Vendor who is reselling
    public function resellerVendor()
    {
        return $this->belongsTo(Vendor::class, 'reseller_vendor_id');
    }

    // Upstream reseller product (when reselling a reseller's listing)
    public function sourceResellerProduct()
    {
        return $this->belongsTo(self::class, 'source_reseller_product_id');
    }

    // Original product owner
    public function ownerVendor()
    {
        return $this->belongsTo(Vendor::class, 'owner_vendor_id');
    }

    /**
     * Set when a write is legitimately synchronising this listing's base price
     * from the authoritative source (the main vendor's product price, or the
     * upstream parent listing's selling price). Never set from request data.
     */
    protected bool $authoritativeBasePriceSync = false;

    /**
     * The ONLY supported way to change an existing listing's base price.
     *
     * A reseller's authority extends to their markup and nothing else — the
     * base price belongs to the main vendor who owns the product. Callers use
     * this method to declare "this write is the owner-price sync, not a
     * reseller edit"; every other attempt to move base_price on an existing
     * row is reverted by the guard in booted().
     */
    public function syncAuthoritativeBasePrice(float|string $basePrice): void
    {
        $this->authoritativeBasePriceSync = true;
        $this->base_price = $basePrice;
    }

    protected static function booted(): void
    {
        static::saving(function (self $resellerProduct) {
            // A reseller may never reduce, replace or otherwise restate the
            // main vendor's base price. Creation sets it from the
            // server-resolved authoritative source; afterwards it may only
            // move through syncAuthoritativeBasePrice(). Anything else is
            // reverted rather than rejected, so a stray write cannot corrupt
            // pricing and cannot crash a legitimate request either.
            if ($resellerProduct->exists
                && $resellerProduct->isDirty('base_price')
                && ! $resellerProduct->authoritativeBasePriceSync) {
                $attempted = $resellerProduct->base_price;
                $resellerProduct->base_price = $resellerProduct->getOriginal('base_price');

                Log::warning('Blocked unauthorized base_price change on a reseller listing; reverted to the authoritative value', [
                    'reseller_product_id' => $resellerProduct->id,
                    'reseller_vendor_id' => $resellerProduct->reseller_vendor_id,
                    'owner_vendor_id' => $resellerProduct->owner_vendor_id,
                    'attempted_base_price' => (string) $attempted,
                    'authoritative_base_price' => (string) $resellerProduct->base_price,
                ]);
            }

            // Structural floors: neither component of a price may be negative,
            // so a listing can never be made to sell below the main vendor's
            // base price, and a negative base can never shrink the total.
            if ((float) $resellerProduct->base_price < 0) {
                $resellerProduct->base_price = 0;
            }

            if ((float) $resellerProduct->markup_price < 0) {
                $resellerProduct->markup_price = 0;
            }

            // selling_price is always derived, never accepted as input:
            // selling = main vendor's base + reseller's markup.
            $resellerProduct->selling_price = Money::sum(
                $resellerProduct->base_price,
                $resellerProduct->markup_price
            );

            $resellerProduct->authoritativeBasePriceSync = false;
        });
    }
}
