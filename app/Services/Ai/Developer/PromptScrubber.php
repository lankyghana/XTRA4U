<?php

namespace App\Services\Ai\Developer;

/**
 * Masks things that must never travel to (or come back from) a model: API keys and webhook secrets in their real shapes,
 * pasted Authorization header lines, long bearer credentials, e-mail addresses, Ghana phone numbers, long digit runs
 * (payment references, card numbers) and long opaque tokens.
 *
 * Deliberately narrower than Logging\Redactor, which is built for log lines and also eats ordinary words: it turns the
 * phrase "Bearer token" into "Bearer [REDACTED]", which would garble every question and answer about authentication.
 * This class never touches plain prose, only credential and personal-data SHAPES.
 */
final class PromptScrubber
{
    public const MASK = '[REDACTED]';

    public static function scrub(string $text): string
    {
        $patterns = [
            // A pasted header line, e.g. "Authorization: Bearer ..." (whole value).
            '/\bauthorization\s*[:=]\s*[^\r\n]+/i' => 'Authorization: '.self::MASK,
            // A bearer credential: only a real-looking one (long), never the word "token" in a sentence.
            '/\bbearer\s+[A-Za-z0-9\-._~+\/=]{20,}/i' => 'Bearer '.self::MASK,
            // Platform and provider key shapes.
            '/\bxt_(?:live|test)_[A-Za-z0-9_]+/' => self::MASK,
            '/\bwhsec_[A-Za-z0-9_]+/' => self::MASK,
            '/\bsk-[A-Za-z0-9_\-]{8,}/' => self::MASK,
            // name=value / name: value credentials with a real-looking value.
            '/\b(api[_-]?key|secret|password|passwd|client[_-]?secret)\b(["\']?\s*[:=]\s*["\']?)[^\s"\',;&]{8,}/i' => '$1$2'.self::MASK,
            // Personal data.
            '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/' => '[EMAIL]',
            '/(?<![\d])(?:\+?233|0)[\s-]?\d{2}[\s-]?\d{3}[\s-]?\d{4}(?![\d])/' => '[PHONE]',
            '/(?<![\d])\d{9,}(?![\d])/' => '[NUMBER]',
            // Long opaque tokens.
            '/(?<![A-Za-z0-9_\-])[A-Za-z0-9_\-]{40,}(?![A-Za-z0-9_\-])/' => self::MASK,
        ];

        foreach ($patterns as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? self::MASK;
        }

        return $text;
    }
}
