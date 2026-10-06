<?php

namespace App\Support\Cms;

use Illuminate\Support\Facades\Cache;

/**
 * One cache entry per public content type. Every admin write forgets the
 * affected keys explicitly (see the model events), and the TTL is short, so an
 * edit is visible on the next request and nothing can go stale for long.
 *
 * Banner / announcement date windows are evaluated per request on top of the
 * cached rows, so scheduled start and expiry never need an invalidation.
 */
final class CmsCache
{
    public const TTL = 3600;

    public const SETTINGS = 'cms.settings';

    public const NAV = 'cms.nav';

    public const BANNERS = 'cms.banners';

    public const ANNOUNCEMENTS = 'cms.announcements';

    public const FAQS = 'cms.faqs';

    public static function remember(string $key, \Closure $callback): mixed
    {
        return Cache::remember($key, self::TTL, $callback);
    }

    public static function pageKey(string $slug): string
    {
        return 'cms.page.'.$slug;
    }

    public static function sectionsKey(string $pageKey): string
    {
        return 'cms.sections.'.$pageKey;
    }

    public static function forget(string ...$keys): void
    {
        foreach ($keys as $key) {
            Cache::forget($key);
        }
    }

    public static function forgetPage(string ...$slugs): void
    {
        foreach ($slugs as $slug) {
            Cache::forget(self::pageKey($slug));
            Cache::forget(self::sectionsKey($slug));
        }
        // The footer / nav can list CMS pages; cheap to refresh as well.
        Cache::forget(self::NAV);
    }

    public static function flushAll(): void
    {
        self::forget(self::SETTINGS, self::NAV, self::BANNERS, self::ANNOUNCEMENTS, self::FAQS);
        foreach (['home', 'about', 'privacy', 'terms'] as $slug) {
            self::forgetPage($slug);
        }
    }
}
