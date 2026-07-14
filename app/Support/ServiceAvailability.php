<?php

namespace App\Support;

use App\Models\Product;
use App\Models\Setting;

/**
 * Runtime, admin-controlled on/off switches for each storefront service
 * category (data, ecg, shop, results, afa). Backed by the `settings` table
 * so an admin can open or close a category instantly without touching .env
 * or redeploying.
 *
 * A category is treated as OPEN unless an admin has explicitly closed it,
 * so the feature is safe by default: a fresh install serves everything.
 */
class ServiceAvailability
{
    /** Settings group used for every key written by this feature. */
    public const GROUP = 'service_availability';

    /** Setting key holding the admin-editable "closed" message. */
    public const MESSAGE_KEY = 'service_availability.message';

    /** Shown to customers when a category is closed and no custom message is set. */
    public const DEFAULT_MESSAGE = 'This service is temporarily unavailable. Please check back soon.';

    /**
     * Category keys managed by this feature, taken from the storefront config
     * so it stays in sync with the categories the marketplace actually offers.
     *
     * @return array<int,string>
     */
    public static function categories(): array
    {
        return array_keys(config('storefront.categories', []));
    }

    /**
     * Setting key used to store a single category's open/closed flag.
     */
    public static function key(string $category): string
    {
        return 'service_open.'.$category;
    }

    public static function isOpen(string $category): bool
    {
        return Setting::get(self::key($category), '1') === '1';
    }

    public static function isClosed(string $category): bool
    {
        return ! self::isOpen($category);
    }

    public static function setOpen(string $category, bool $open): void
    {
        Setting::set(self::key($category), $open ? '1' : '0', self::GROUP);
    }

    /**
     * Open/closed flag for every managed category.
     *
     * @return array<string,bool> category => isOpen
     */
    public static function statuses(): array
    {
        $statuses = [];
        foreach (self::categories() as $category) {
            $statuses[$category] = self::isOpen($category);
        }

        return $statuses;
    }

    public static function message(): string
    {
        $message = Setting::get(self::MESSAGE_KEY);

        return is_string($message) && trim($message) !== ''
            ? $message
            : self::DEFAULT_MESSAGE;
    }

    public static function setMessage(?string $message): void
    {
        Setting::set(self::MESSAGE_KEY, (string) ($message ?? ''), self::GROUP);
    }

    /**
     * Resolve the storefront category for a regular product from its
     * description metadata (mirrors StorefrontController), falling back to the
     * configured default category when none is present.
     */
    public static function categoryForProduct(?Product $product): string
    {
        $default = (string) config('storefront.default_category', 'data');

        if (! $product) {
            return $default;
        }

        $decoded = json_decode((string) $product->description, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && ! empty($decoded['category'])) {
            return (string) $decoded['category'];
        }

        return $default;
    }
}
