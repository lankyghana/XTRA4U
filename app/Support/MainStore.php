<?php

namespace App\Support;

use App\Models\Vendor;

/**
 * Resolves the platform's flagship storefront for public entry points
 * (homepage "Buy Now", the Results Checker link in the main navigation).
 *
 * The configured `storefront.main_store_code` is preferred, but it must never
 * be trusted blindly: in local/staging databases the production vendor code
 * does not exist, and a hardcoded link would 404. When the configured vendor
 * is missing we fall back to any approved vendor so the entry links stay alive.
 */
class MainStore
{
    public static function vendor(): ?Vendor
    {
        $code = config('storefront.main_store_code');

        $vendor = $code
            ? Vendor::where('vendor_code', $code)->first()
            : null;

        return $vendor
            ?? Vendor::where('is_approved', true)->orderBy('id')->first()
            ?? Vendor::query()->orderBy('id')->first();
    }
}
