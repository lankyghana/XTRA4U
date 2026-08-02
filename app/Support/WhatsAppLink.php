<?php

namespace App\Support;

class WhatsAppLink
{
    /**
     * Minimum digit count for a normalized number to be considered valid
     * (country code + subscriber number, e.g. 233 + 9 digits = 12).
     */
    private const MIN_DIGITS = 10;

    /**
     * Build a wa.me deep link from a local or international phone number and a prefilled message.
     * Returns null if the number is missing or cannot be normalized into a plausible international number.
     */
    public static function url(?string $number, string $message): ?string
    {
        $digits = self::normalize((string) $number);

        if ($digits === null) {
            return null;
        }

        return "https://wa.me/{$digits}?text=" . urlencode($message);
    }

    /**
     * Normalize a local or international phone number into WhatsApp's wa.me digit format
     * (country code + subscriber number, no leading zero or plus sign).
     */
    public static function normalize(string $number): ?string
    {
        $digits = preg_replace('/\D+/', '', trim($number));

        if ($digits === null || $digits === '') {
            return null;
        }

        $countryCode = (string) config('services.whatsapp.default_country_code', '233');

        if ($countryCode !== '' && str_starts_with($digits, $countryCode . '0')) {
            // Local leading 0 mistakenly kept after the country code, e.g. 2330244000000
            $digits = $countryCode . substr($digits, strlen($countryCode) + 1);
        } elseif (str_starts_with($digits, '0')) {
            // Local format, e.g. 024XXXXXXX -> replace the leading 0 with the country code
            $digits = $countryCode . substr($digits, 1);
        } elseif ($countryCode !== '' && !str_starts_with($digits, $countryCode)) {
            // No recognizable country code prefix — assume a bare local subscriber number
            $digits = $countryCode . $digits;
        }

        return strlen($digits) >= self::MIN_DIGITS ? $digits : null;
    }
}
