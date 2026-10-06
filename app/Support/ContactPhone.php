<?php

namespace App\Support;

/**
 * Canonicalises vendor phone numbers for contact exports (de-duplication + output format).
 *
 * Ghana numbers are accepted as 0XXXXXXXXX, 233XXXXXXXXX, +233XXXXXXXXX, 00233XXXXXXXXX or a bare
 * 9-digit national number (the same assumption the payment services already make). Numbers written with
 * an explicit non-Ghana international prefix (+ or 00) are kept as-is, never rewritten to 233.
 */
final class ContactPhone
{
    /**
     * @return array{key: string, local: string, international: string}|null null when unusable
     */
    public static function parse(?string $raw): ?array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        $explicitIntl = str_starts_with($raw, '+') || str_starts_with($raw, '00');
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($explicitIntl && str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        $national = null;
        if (str_starts_with($digits, '233') && strlen($digits) === 12) {
            $national = substr($digits, 3);
        } elseif (! $explicitIntl && str_starts_with($digits, '0') && strlen($digits) === 10) {
            $national = substr($digits, 1);
        } elseif (! $explicitIntl && strlen($digits) === 9) {
            $national = $digits;
        }

        if ($national !== null) {
            if (! preg_match('/^[2-5]\d{8}$/', $national)) {
                return null;
            }

            return [
                'key' => '233'.$national,
                'local' => '0'.$national,
                'international' => '+233'.$national,
            ];
        }

        // Non-Ghana international number: only trusted when explicitly written as such (E.164: 8-15 digits).
        if ($explicitIntl && preg_match('/^[1-9]\d{7,14}$/', $digits)) {
            return ['key' => $digits, 'local' => '+'.$digits, 'international' => '+'.$digits];
        }

        return null;
    }
}
