<?php

namespace App\Services\Ussd\GwoSevo;

use Illuminate\Http\JsonResponse;

/**
 * Wire-format knowledge for the GWO SEVO USSD gateway, kept out of the shared
 * menu logic so the internal "CON "/"END " vocabulary never reaches the gateway.
 */
class GwoSevoProtocol
{
    public const OP_START = 1;

    public const OP_CONTINUE = 18;

    public const RESPONSE_CONTINUE = 2;

    public const RESPONSE_END = 17;

    /** Per the GWO SEVO guide: 01 MTN, 02 Telecel, 04 AT. */
    private const NETWORKS = [
        '01' => 'MTN',
        '02' => 'Telecel',
        '04' => 'AT',
    ];

    public static function networkName(string $code): ?string
    {
        return self::NETWORKS[$code] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public static function networkCodes(): array
    {
        return array_keys(self::NETWORKS);
    }

    /**
     * Ghanaian MSISDN (233XXXXXXXXX, +233..., 0XXXXXXXXX or XXXXXXXXX) to the
     * local 0XXXXXXXXX form the rest of the USSD stack stores and pays with.
     */
    public static function localMsisdn(string $msisdn): ?string
    {
        $digits = ltrim(trim($msisdn), '+');

        if ($digits === '' || ! ctype_digit($digits)) {
            return null;
        }

        if (str_starts_with($digits, '233') && strlen($digits) === 12) {
            $digits = '0'.substr($digits, 3);
        } elseif (strlen($digits) === 9) {
            $digits = '0'.$digits;
        }

        return strlen($digits) === 10 && $digits[0] === '0' ? $digits : null;
    }

    /**
     * Last four digits only; the full number never goes to logs or audit rows.
     */
    public static function maskMsisdn(string $msisdn): string
    {
        $digits = preg_replace('/\D/', '', $msisdn) ?? '';

        return $digits === '' ? '' : '******'.substr($digits, -4);
    }

    /**
     * Deterministic internal key for a gateway session.
     *
     * GWO SEVO ids may be short and are not guaranteed unique across
     * subscribers or over time, and our validator for the other gateway
     * demands 6+ characters. Hashing msisdn + id yields a fixed-length key in
     * a namespace the other gateway cannot produce (UssdGatewayRequest rejects
     * ids starting "gwosevo:"), and stops one subscriber continuing another's
     * session.
     */
    public static function internalSessionKey(string $gatewaySessionId, string $localMsisdn): string
    {
        return 'gwosevo:'.hash_hmac('sha256', $localMsisdn.'|'.$gatewaySessionId, (string) config('app.key'));
    }

    /**
     * Translate the internal "CON x"/"END x" reply into GWO SEVO's JSON.
     */
    public static function fromInternal(string $internal, string $sessionId): JsonResponse
    {
        if (str_starts_with($internal, 'CON ')) {
            return self::json(substr($internal, 4), self::RESPONSE_CONTINUE, $sessionId);
        }

        return self::json(str_starts_with($internal, 'END ') ? substr($internal, 4) : $internal, self::RESPONSE_END, $sessionId);
    }

    public static function end(string $message, string $sessionId = ''): JsonResponse
    {
        return self::json($message, self::RESPONSE_END, $sessionId);
    }

    private static function json(string $message, int $op, string $sessionId): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'ussdServiceOp' => $op,
            'sessionID' => $sessionId,
        ]);
    }
}
