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
        
        $middleware->alias([
            'vendor.approved' => EnsureVendorApproved::class,
            'admin.only' => AdminOnly::class,
            'prune.purchase.tokens' => PrunePurchaseTokens::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
