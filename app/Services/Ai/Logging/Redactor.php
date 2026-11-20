<?php

namespace App\Services\Ai\Logging;

/**
 * Masks secrets and personal data. Used on every string that can reach a log or an exception message, and as
 * a last line of defence on log context. It is a safety net, NOT permission to log content: prompts, responses,
 * tool arguments and message text are never passed to the logger in the first place.
 */
final class Redactor
{
    public const MASK = '[REDACTED]';

    /** Context keys that are dropped entirely (name contains any of these, case-insensitive). */
    private const SENSITIVE_KEYS = [
        'authorization', 'api_key', 'apikey', 'api-key', 'secret', 'token', 'password', 'passwd', 'cookie',
        'credential', 'header', 'prompt', 'message', 'content', 'response', 'completion', 'text', 'argument',
        'phone', 'msisdn', 'reference', 'email', 'body', 'payload', 'pin',
    ];

    /** Numeric accounting fields whose names merely contain a sensitive word ("tokens" are counts, not secrets). */
    private const SAFE_KEYS = ['input_tokens', 'output_tokens', 'total_tokens', 'estimated_tokens'];

    public static function redact(string $value): string
    {
        $patterns = [
            // Authorization header lines and bearer/basic credentials.
            '/\bauthorization\s*[:=]\s*[^\r\n]+/i' => 'Authorization: '.self::MASK,
            '/\b(bearer|basic)\s+[A-Za-z0-9\-._~+\/=]{6,}/i' => '$1 '.self::MASK,
            // key=value / key: value style secrets.
            '/\b(api[_-]?key|secret|token|password|passwd|pwd|client[_-]?secret)\b(["\']?\s*[:=]\s*["\']?)[^\s"\',;&]+/i' => '$1$2'.self::MASK,
            // Provider / platform key shapes.
            '/\bsk-[A-Za-z0-9_\-]{8,}/' => self::MASK,
            '/\bxt_(?:live|test)_[A-Za-z0-9_]+/' => self::MASK,
            '/\bwhsec_[A-Za-z0-9_]+/' => self::MASK,
            // Email addresses.
            '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/' => '[EMAIL]',
            // Ghana-style phone numbers: 0XX XXX XXXX, +233 XX XXX XXXX, 233XXXXXXXXX (spaces/dashes allowed).
            '/(?<![\d])(?:\+?233|0)[\s-]?\d{2}[\s-]?\d{3}[\s-]?\d{4}(?![\d])/' => '[PHONE]',
            // Any other long digit run (card numbers, numeric payment references, ids).
            '/(?<![\d])\d{9,}(?![\d])/' => '[NUMBER]',
            // Long opaque tokens.
            '/(?<![A-Za-z0-9_\-])[A-Za-z0-9_\-]{40,}(?![A-Za-z0-9_\-])/' => self::MASK,
        ];

        foreach ($patterns as $pattern => $replacement) {
            $value = preg_replace($pattern, $replacement, $value) ?? self::MASK;
        }

        return $value;
    }

    /**
     * Sanitise a log context: sensitive keys are dropped, strings redacted, depth limited.
     *
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public static function context(array $context, int $depth = 0): array
    {
        $out = [];

        foreach ($context as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                continue;
            }

            if (is_string($value)) {
                $out[$key] = self::redact($value);
            } elseif (is_array($value)) {
                if ($depth < 2) {
                    $out[$key] = self::context($value, $depth + 1);
                }
            } elseif (is_scalar($value) || $value === null) {
                $out[$key] = $value;
            }
            // Objects and resources are dropped on purpose.
        }

        return $out;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $k = strtolower($key);
        if (in_array($k, self::SAFE_KEYS, true)) {
            return false;
        }

        foreach (self::SENSITIVE_KEYS as $needle) {
            if (str_contains($k, $needle)) {
                return true;
            }
        }

        return false;
    }
}
