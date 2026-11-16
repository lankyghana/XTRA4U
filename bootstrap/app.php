<?php

use App\Http\Middleware\AdminOnly;
use App\Http\Middleware\ContentSecurityPolicy;
use App\Http\Middleware\EnsureVendorApproved;
use App\Http\Middleware\PrunePurchaseTokens;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Public Developer API. Deliberately NOT in the `web` group: no session,
            // no CSRF, no cookies, no CSP middleware. Existing routes are unaffected.
            \Illuminate\Support\Facades\Route::middleware(['api.context'])
                ->prefix('api/v1')
                ->name('developer-api.')
                ->group(base_path('routes/api_v1.php'));

            // Developer portal (/developers): a normal web (session + CSRF) surface with its own guard.
            \Illuminate\Support\Facades\Route::middleware('web')
                ->group(base_path('routes/developers.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
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
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Http\Middleware\EnsureCmsAdmin::class,
        );

        // Same rule for every admin route: authorize BEFORE implicit model binding, so a
        // non-admin can never distinguish "no such order/vendor" (404) from "exists" (403/redirect).
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Http\Middleware\AdminOnly::class,
        );

        $middleware->alias([
            'vendor.approved' => EnsureVendorApproved::class,
            'admin.only' => AdminOnly::class,
            'prune.purchase.tokens' => PrunePurchaseTokens::class,
            'ussd.gateway' => \App\Http\Middleware\EnsureUssdGatewayRequest::class,
            'ussd.gwosevo' => \App\Http\Middleware\EnsureGwoSevoGatewayRequest::class,
            'cms.admin' => \App\Http\Middleware\EnsureCmsAdmin::class,
            // Developer API (api/v1)
            'developer.auth' => \App\Http\Middleware\DeveloperApi\EnsureDeveloperAuthenticated::class,
            'api.context' => \App\Http\Middleware\DeveloperApi\ApiRequestContext::class,
            'api.key' => \App\Http\Middleware\DeveloperApi\AuthenticateApiKey::class,
            'api.throttle' => \App\Http\Middleware\DeveloperApi\ThrottleApiApplication::class,
            'api.scope' => \App\Http\Middleware\DeveloperApi\RequireApiScope::class,
            'api.production' => \App\Http\Middleware\DeveloperApi\RequireProductionEnvironment::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every failure under /api/v1 is rendered in the standard API error envelope,
        // and never leaks internals. Other routes keep Laravel's default handling.
        $exceptions->dontReport(\App\Support\DeveloperApi\ApiException::class);
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if (\App\Support\DeveloperApi\ApiExceptionRenderer::handles($request)) {
                return \App\Support\DeveloperApi\ApiExceptionRenderer::render($e, $request);
            }

            return null;
        });
    })->create();
