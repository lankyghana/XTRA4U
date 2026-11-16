<?php

namespace App\Http\Middleware\DeveloperApi;

use App\Models\Developer\DeveloperApiRequest;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Outermost API middleware: assigns the request id, tags the response with it
 * and (after the response is sent) records ONE metadata row per call.
 *
 * What is logged: method, route pattern, status, duration, error code, ip,
 * application/key ids. Never headers, query strings, bodies or secrets.
 */
class ApiRequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('api.request_id', 'req_'.Str::lower((string) Str::ulid()));
        $request->attributes->set('api.started_at', microtime(true));

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('X-Request-Id', $request->attributes->get('api.request_id'));

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $app = $request->attributes->get('api.application');
            $key = $request->attributes->get('api.key');
            $started = (float) $request->attributes->get('api.started_at', microtime(true));

            DeveloperApiRequest::create([
                'request_id' => $request->attributes->get('api.request_id'),
                'application_id' => $app?->id,
                'api_key_id' => $key?->id,
                'environment' => $request->attributes->get('api.environment'),
                'method' => $request->getMethod(),
                'path' => '/'.ltrim($request->route()?->uri() ?? $request->path(), '/'),
                'status_code' => $response->getStatusCode(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'error_code' => $request->attributes->get('api.error_code'),
                'ip' => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            // Logging must never affect the API.
            Log::warning('Developer API request log failed', ['error' => $e->getMessage()]);
        }
    }
}
