<?php

namespace App\Services\Ussd;

/**
 * Splits the aggregator's `text` field into the dialled prefix and menu input.
 *
 * The prefix is whatever followed the base code when the customer dialled:
 * for *203*45*102# with base *203*, the gateway sends text = "45*102". The
 * number of prefix segments is decided once, when the session is created (see
 * UssdVendorResolver), and stored on the session — it cannot be re-derived
 * later because menu keypresses are numeric too, and "1*2" is indistinguishable
 * from an extension/vendor-id pair.
 */
class UssdRequestParser
{
    /**
     * @return array<int, string>
     */
    public function segments(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        return array_map('trim', explode('*', $text));
    }

    /**
     * The keypress this request represents.
     *
     * Aggregators differ: some send the full cumulative string ("45*102*1*2"),
     * others send only the latest entry ("2"). Comparing the segment count to
     * the known prefix length distinguishes the two without configuration.
     */
    public function currentInput(string $text, int $prefixLength): string
    {
        $segments = $this->segments($text);

        if ($segments === []) {
            return '';
        }

        // Cumulative gateway: drop the dialled prefix, take the latest entry.
        if (count($segments) > $prefixLength) {
            $remaining = array_slice($segments, $prefixLength);

            return $remaining === [] ? '' : (string) end($remaining);
        }

        // Non-cumulative gateway: the whole text is the latest entry.
        return (string) end($segments);
    }

    public function isNumericSegment(?string $segment): bool
    {
        return is_string($segment) && $segment !== '' && ctype_digit($segment);
    }
}
