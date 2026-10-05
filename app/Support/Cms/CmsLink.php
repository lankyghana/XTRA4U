<?php

namespace App\Support\Cms;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

/**
 * Validation and rendering of every admin-entered link.
 *
 * Accepted:   {shop}                (the flagship storefront, resolved at render time)
 *             /internal/path        (must match a PUBLIC route; never admin / authenticated areas)
 *             https://host/...      (external, https only)
 *             mailto:  tel:
 * Rejected:   javascript:, data:, vbscript:, http:, protocol-relative //host, backslashes,
 *             control characters, and any internal path that resolves to a protected route.
 *
 * validate() is used on input; safe() is used again when rendering, because the
 * database is not treated as trusted.
 */
final class CmsLink
{
    public const SHOP_TOKEN = '{shop}';

    /** Middleware that mark a route as non-public. */
    private const PROTECTED_MIDDLEWARE = ['admin.only', 'cms.admin', 'vendor.approved', 'auth'];

    /** Paths that must never be linked even if they happen to be public routes. */
    private const DENY_PREFIXES = ['/admin', '/api', '/webhooks', '/clear-cache', '/storage-link', '/csrf-token', '/payment', '/purchase'];

    /** Returns null when valid, else a human-readable reason. */
    public static function validate(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        if (mb_strlen($url) > 255) {
            return 'Links can be at most 255 characters.';
        }
        if (preg_match('/[\x00-\x1F\x7F\\\\\s]/u', $url)) {
            return 'Links cannot contain spaces, control characters or backslashes.';
        }
        if ($url === self::SHOP_TOKEN) {
            return null;
        }
        if (str_starts_with($url, '/')) {
            return self::validateInternal($url);
        }
        if (preg_match('#^https://[^/?\#]+#i', $url)) {
            $host = parse_url($url, PHP_URL_HOST);
            if (! is_string($host) || ! str_contains($host, '.') || filter_var($url, FILTER_VALIDATE_URL) === false) {
                return 'That does not look like a valid https:// address.';
            }

            return null;
        }
        if (preg_match('/^mailto:[^@\s]+@[^@\s]+\.[^@\s]+$/i', $url)) {
            return null;
        }
        if (preg_match('/^tel:\+?[0-9][0-9\-\.]{4,20}$/', $url)) {
            return null;
        }

        return 'Use a page path like /about, an https:// link, mailto:, tel:, or {shop}.';
    }

    private static function validateInternal(string $path): ?string
    {
        if (str_starts_with($path, '//')) {
            return 'Protocol-relative links are not allowed.';
        }

        $clean = '/'.ltrim((string) parse_url($path, PHP_URL_PATH), '/');
        $lower = strtolower($clean);

        foreach (self::DENY_PREFIXES as $prefix) {
            if ($lower === $prefix || str_starts_with($lower, $prefix.'/')) {
                return 'That destination is not a public page.';
            }
        }

        try {
            $route = app('router')->getRoutes()->match(Request::create($clean, 'GET'));
        } catch (ResourceNotFoundException|HttpException) {
            return 'No public page exists at that path.';
        }

        $middleware = (array) $route->gatherMiddleware();
        foreach ($middleware as $m) {
            if (is_string($m) && (in_array($m, self::PROTECTED_MIDDLEWARE, true) || str_starts_with($m, 'auth:'))) {
                return 'That destination is not a public page.';
            }
        }

        // /vendor/* is mostly the authenticated dashboard (routes group-guarded
        // via the controller); only the public entry points may be linked.
        if (str_starts_with($lower, '/vendor/')) {
            $public = ['/vendor/login', '/vendor/request', '/vendor/request/create', '/vendor/request/pending', '/vendor/forgot-password'];
            if (! in_array(rtrim($lower, '/'), $public, true)) {
                return 'That destination is not a public page.';
            }
        }

        return null;
    }

    /**
     * The URL to emit, or null if the stored value is unusable. Resolves {shop}.
     */
    public static function safe(?string $url, ?string $shopUrl = null): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        if ($url === self::SHOP_TOKEN) {
            return $shopUrl ?: route('checkout.show');
        }
        // Render-time re-check is syntactic only (no route matching per link).
        if (preg_match('/[\x00-\x1F\x7F\\\\\s]/u', $url)) {
            return null;
        }
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $url;
        }
        if (preg_match('#^https://[^/?\#]+#i', $url) && filter_var($url, FILTER_VALIDATE_URL) !== false) {
            return $url;
        }
        if (preg_match('/^mailto:[^@\s]+@[^@\s]+\.[^@\s]+$/i', $url) || preg_match('/^tel:\+?[0-9][0-9\-\.]{4,20}$/', $url)) {
            return $url;
        }

        return null;
    }

    public static function isExternal(?string $url): bool
    {
        return is_string($url) && preg_match('#^https://#i', $url) === 1;
    }
}
