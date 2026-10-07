<?php

namespace App\Support;

/**
 * Storefront category slots whose old "platform service" model has been superseded.
 *
 * "ecg" used to be a top-level XTRA4U service: a vendor-assigned product catalog with its own
 * open/closed toggle. Electricity is now just ONE BILLER inside the global Utility Bills service
 * (Admin > Utility Bills Settings), fulfilled by KiNG FLEXY, with no vendor assignment.
 *
 * The `ecg` key itself is kept because (a) historical/legacy vendor ECG products and orders carry
 * `category: ecg` in their metadata and must stay readable, and (b) it is the storefront category slot
 * under which the Utility Bills card appears. What is retired is the admin-facing platform-service
 * model: such categories are not listed, assigned or toggled in Service Availability / Platform
 * Service Vendors, and vendors cannot create NEW products in them.
 */
final class SupersededCategories
{
    /** category key => where it is managed now */
    public const KEYS = ['ecg'];

    /** Customer-facing wording for the Utility Bills category slot. */
    public const UTILITY_BILLS_KEY = 'ecg';

    public const UTILITY_BILLS_LABEL = 'Utility Bills';

    public const UTILITY_BILLS_DESCRIPTION = 'Pay electricity, water and TV bills.';

    public static function is(string $category): bool
    {
        return in_array($category, self::KEYS, true);
    }

    /**
     * @param  array<int,string>  $categories
     * @return array<int,string>
     */
    public static function without(array $categories): array
    {
        return array_values(array_filter($categories, fn (string $c) => ! self::is($c)));
    }
}
