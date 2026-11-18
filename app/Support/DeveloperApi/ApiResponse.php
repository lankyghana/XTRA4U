<?php

namespace App\Support\DeveloperApi;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The single place the public API envelope is built. */
final class ApiResponse
{
    public static function requestId(?Request $request = null): string
    {
        $request ??= request();

        return (string) ($request->attributes->get('api.request_id') ?? 'req_unknown');
    }

    public static function success(mixed $data = null, string $message = 'OK', array $meta = [], int $status = 200, array $headers = []): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data ?? new \stdClass,
            'meta' => ['request_id' => self::requestId()] + self::environmentMeta() + $meta,
        ], $status, $headers);
    }

    /** Sandbox responses say so in the envelope; production responses are unchanged. */
    private static function environmentMeta(): array
    {
        return request()->attributes->get('api.environment') === 'sandbox' ? ['environment' => 'sandbox'] : [];
    }

    public static function error(string $code, string $message, int $status, array $details = [], array $headers = []): JsonResponse
    {
        request()->attributes->set('api.error_code', $code);

        return response()->json([
            'success' => false,
            'message' => $message,
            'error' => [
                'code' => $code,
                'details' => $details === [] ? new \stdClass : $details,
            ],
            'meta' => ['request_id' => self::requestId()] + self::environmentMeta(),
        ], $status, $headers);
    }
}
