<?php

namespace App\Support\DeveloperApi;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Converts every failure on /api/v1/* into the standard error envelope.
 * Unknown (500) failures NEVER leak their message, class or trace.
 */
final class ApiExceptionRenderer
{
    public static function handles(Request $request): bool
    {
        return $request->is('api/v1/*') || $request->is('api/v1');
    }

    public static function render(Throwable $e, Request $request): JsonResponse
    {
        if ($e instanceof ApiException) {
            return ApiResponse::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->details, $e->headers);
        }

        if ($e instanceof ValidationException) {
            return ApiResponse::error(ApiError::VALIDATION_FAILED, 'The request failed validation.', 422, [
                'fields' => $e->errors(),
            ]);
        }

        if ($e instanceof AuthenticationException) {
            return ApiResponse::error(ApiError::INVALID_API_KEY, 'Invalid or missing API credentials.', 401);
        }

        if ($e instanceof AuthorizationException) {
            return ApiResponse::error(ApiError::FORBIDDEN, 'This API key is not permitted to perform this action.', 403);
        }

        if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
            return ApiResponse::error(ApiError::NOT_FOUND, 'Resource not found.', 404);
        }

        if ($e instanceof ThrottleRequestsException) {
            return ApiResponse::error(ApiError::RATE_LIMITED, 'Too many requests.', 429, [], $e->getHeaders());
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            return ApiResponse::error(
                $status === 405 ? 'METHOD_NOT_ALLOWED' : 'HTTP_ERROR',
                $status === 405 ? 'Method not allowed.' : 'The request could not be processed.',
                $status,
                [],
                $e->getHeaders()
            );
        }

        Log::error('Developer API unhandled exception', [
            'request_id' => ApiResponse::requestId($request),
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        return ApiResponse::error(ApiError::INTERNAL_ERROR, 'An unexpected error occurred.', 500);
    }
}
