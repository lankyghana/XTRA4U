<?php

use App\Http\Middleware\AdminOnly;
use App\Http\Middleware\ContentSecurityPolicy;
use App\Http\Middleware\DeveloperApi\ApiRequestContext;
use App\Http\Middleware\DeveloperApi\AuthenticateApiKey;
use App\Http\Middleware\DeveloperApi\EnsureVendorDeveloperAccess;
use App\Http\Middleware\DeveloperApi\RequireApiScope;
use App\Http\Middleware\DeveloperApi\RequireKnownEnvironment;
use App\Http\Middleware\DeveloperApi\RequireSandboxEnvironment;
use App\Http\Middleware\DeveloperApi\ThrottleApiApplication;
use App\Http\Middleware\EnsureCmsAdmin;
use App\Http\Middleware\EnsureGwoSevoGatewayRequest;
use App\Http\Middleware\EnsureUssdGatewayRequest;
use App\Http\Middleware\EnsureVendorApproved;
use App\Http\Middleware\PrunePurchaseTokens;
use App\Support\DeveloperApi\ApiException;
use App\Support\DeveloperApi\ApiExceptionRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Public Developer API. Deliberately NOT in the `web` group: no session,
            // no CSRF, no cookies, no CSP middleware. Existing routes are unaffected.
            Route::middleware(['api.context'])
                ->prefix('api/v1')
                ->name('developer-api.')
                ->group(base_path('routes/api_v1.php'));

            // Developer portal (/developers): a normal web (session + CSRF) surface. It reuses the existing `vendor` guard.
            Route::middleware('web')
                ->group(base_path('routes/developers.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Which X-Forwarded-* headers are believed FROM A TRUSTED PROXY. The proxies themselves come from TRUSTED_PROXIES
        // via config/trustedproxy.php (empty by default = nobody is trusted; wildcards are rejected there). Only the four
        // headers Symfony needs for the client address, scheme, host and port are honoured: not the prefix header and
        // not the AWS-ELB variant, which this application has no use for.
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);

        // Global middleware for all web requests
        $middleware->web(append: [
            ContentSecurityPolicy::class,
        ]);

        // Exclude webhook routes from CSRF verification
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
            'payment/callback',
        ]);

        // CMS routes must authenticate BEFORE implicit model binding runs; otherwise an
        // anonymous visitor could tell "no such record" (404) from "exists" (redirect).
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: EnsureCmsAdmin::class,
        );

        // Same rule for every admin route: authorize BEFORE implicit model binding, so a
        // non-admin can never distinguish "no such order/vendor" (404) from "exists" (403/redirect).
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: AdminOnly::class,
        );

        $middleware->alias([
            'vendor.approved' => EnsureVendorApproved::class,
            'admin.only' => AdminOnly::class,
            'prune.purchase.tokens' => PrunePurchaseTokens::class,
            'ussd.gateway' => EnsureUssdGatewayRequest::class,
            'ussd.gwosevo' => EnsureGwoSevoGatewayRequest::class,
            'cms.admin' => EnsureCmsAdmin::class,
            // Developer API (api/v1)
            'developer.access' => EnsureVendorDeveloperAccess::class,
            'api.context' => ApiRequestContext::class,
            'api.key' => AuthenticateApiKey::class,
            'api.throttle' => ThrottleApiApplication::class,
            'api.scope' => RequireApiScope::class,
            'api.env' => RequireKnownEnvironment::class,
            'api.sandbox' => RequireSandboxEnvironment::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every failure under /api/v1 is rendered in the standard API error envelope,
        // and never leaks internals. Other routes keep Laravel's default handling.
        $exceptions->dontReport(ApiException::class);
        $exceptions->render(function (Throwable $e, Request $request) {
            if (ApiExceptionRenderer::handles($request)) {
                return ApiExceptionRenderer::render($e, $request);
            }

            return null;
        });
    })->create();
