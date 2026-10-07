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
            'cms.admin' => \App\Http\Middleware\EnsureCmsAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
