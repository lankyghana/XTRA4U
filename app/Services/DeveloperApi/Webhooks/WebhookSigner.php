<?php

namespace App\Services\DeveloperApi\Webhooks;

use Illuminate\Support\Str;

/**
 * Webhook signatures.
 *
 *   X-XTRA4U-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256 of "<t>.<raw body>" with the signing secret>
 *
 * The timestamp is inside the signed material, so a captured request cannot be
 * replayed outside the receiver's tolerance window. Receivers MUST verify against
 * the RAW body bytes and compare in constant time (hash_equals / hmac.compare_digest).
 */
final class WebhookSigner
{
    public const HEADER = 'X-XTRA4U-Signature';

    public const SECRET_PREFIX = 'whsec_';

    public static function generateSecret(): string
    {
        return self::SECRET_PREFIX.Str::random(40);
    }

    public static function signature(string $secret, int $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    public static function header(string $secret, int $timestamp, string $body): string
    {
        return 't='.$timestamp.',v1='.self::signature($secret, $timestamp, $body);
    }

    /** Reference verifier — the same logic a receiver should implement. */
    public static function verify(string $header, string $body, string $secret, int $toleranceSeconds = 300, ?int $now = null): bool
    {
        $timestamp = null;
        $candidates = [];

        foreach (explode(',', $header) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) {
                continue;
            }
            if ($kv[0] === 't' && ctype_digit($kv[1])) {
                $timestamp = (int) $kv[1];
            } elseif ($kv[0] === 'v1') {
                $candidates[] = $kv[1];
            }
        }

        if ($timestamp === null || $candidates === []) {
            return false;
        }

        if (abs(($now ?? time()) - $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expected = self::signature($secret, $timestamp, $body);
        foreach ($candidates as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
