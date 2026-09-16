<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;

class Product extends Model
{
    /**
     * Products are SOFT deleted.
     *
     * A product row is the only record of what a historical order actually
     * bought — its provider service mapping, its network, its capacity, and
     * the price its owner had set. Hard-deleting one destroyed that evidence
     * permanently (orders survived via the `nullOnDelete` FK, but with a
     * dangling NULL `vendor_service_id` and no way to ever identify the
     * service again), which is exactly what made auditing historical orders
     * impossible. Deleted products stay out of every storefront and listing
     * automatically via the global scope, while remaining reachable for
     * historical orders and admin investigation.
     */
    use SoftDeletes;

    protected $fillable = [
        'vendor_id',
        'name',
        'description',
        'price',
        'image_path',
        'is_active',
        'is_resellable',
        'min_base_price',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_resellable' => 'boolean',
        'price' => 'decimal:2',
        'min_base_price' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $product) {
            // Ownership is immutable. A product belongs to the main vendor who
            // created it; nothing may reassign it — not a crafted request, not
            // a mass assignment, not a future bulk tool. Reverted rather than
            // thrown so a stray write cannot corrupt ownership and cannot
            // break a legitimate request either.
            if ($product->exists && $product->isDirty('vendor_id')) {
                $attempted = $product->vendor_id;
                $product->vendor_id = $product->getOriginal('vendor_id');

                Log::warning('Blocked attempt to change product ownership; reverted to the original vendor', [
                    'product_id' => $product->id,
                    'attempted_vendor_id' => $attempted,
                    'owner_vendor_id' => $product->vendor_id,
                ]);
            }
        });

        static::deleting(function (self $product) {
            // Belt and braces alongside the soft-delete global scope: a
            // deleted product is also inactive, so any query that filters only
            // on is_active (rather than joining through the scope) can never
            // surface it for sale either.
            if (! $product->isForceDeleting() && $product->is_active) {
                $product->forceFill(['is_active' => false])->saveQuietly();
            }
        });
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    // Reseller products (vendors reselling this product)
    public function resellerProducts()
    {
        return $this->hasMany(ResellerProduct::class);
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? asset('storage/' . $this->image_path) : null;
    }

    public function getDecodedDescriptionAttribute(): array
    {
        $value = $this->description;
        if (! $value) {
            return [];
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded)
            ? $decoded
            : [];
    }
}
