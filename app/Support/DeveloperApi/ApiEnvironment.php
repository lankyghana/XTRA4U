<?php

namespace App\Support\DeveloperApi;

use Illuminate\Http\Request;

/**
 * The ONE place the environment of an API request is read.
 *
 * It comes from the authenticated API key (AuthenticateApiKey stores it as a request ATTRIBUTE, which a
 * client cannot set: query strings, JSON bodies and headers live elsewhere). Anything else — missing,
 * empty, unknown — is refused, so an environment that cannot be established safely never falls through
 * to production processing (or sandbox).
 */
final class ApiEnvironment
{
    public const SANDBOX = 'sandbox';

    public const PRODUCTION = 'production';

    public static function of(Request $request): string
    {
        $env = $request->attributes->get('api.environment');

        if ($env !== self::SANDBOX && $env !== self::PRODUCTION) {
            throw new ApiException(ApiError::ENVIRONMENT_MISMATCH, 'The API environment could not be determined for this request.', 403);
        }

        return $env;
    }

    public static function isSandbox(Request $request): bool
    {
        return self::of($request) === self::SANDBOX;
    }
}
