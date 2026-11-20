<?php

namespace App\Services\Ai\Developer\Tools;

/**
 * Free text that a vendor typed (application names and descriptions) is UNTRUSTED data. Before it goes anywhere near a
 * model it is reduced to a short, single-line, markup-free, link-free string, so it cannot carry formatting, hidden
 * markup or a URL to follow. (M5.1 section 14 / docs/ai/02-tool-system.md section 10.)
 */
final class SafeText
{
    public static function clean(mixed $value, int $max): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        $text = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('~(?:https?://|ftp://|www\.)\S+~i', '[link]', $text);
        $text = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)).'…' : $text;
    }
}
