<?php

use App\Http\Controllers\DeveloperApi\CatalogController;
use App\Http\Controllers\DeveloperApi\HealthController;
use App\Http\Controllers\DeveloperApi\OrderController;
use App\Http\Controllers\DeveloperApi\PaymentController;
use App\Http\Controllers\DeveloperApi\QuoteController;
use App\Http\Controllers\DeveloperApi\SandboxController;
use Illuminate\Support\Facades\Route;

/*
| Public Developer API, mounted at /api/v1 (see bootstrap/app.php). Stateless:
| no session, no CSRF, no cookies. Every route except /health requires an API key.
|
| ENVIRONMENT: decided solely by the API key (xt_test_ = sandbox, xt_live_ = production). The same URLs
| serve both; api.env refuses the request if the key did not establish a known environment, and each
| controller branches on it BEFORE doing anything else. Sandbox ids are prefixed `sbx_` so they can never
| be mistaken for (or collide with) production ids: `sbx_ord_…` / `sbx_pay_…` / `sbx_qt_…`.
*/

Route::get('health', HealthController::class)->middleware('throttle:60,1')->name('health');

Route::middleware(['api.key', 'api.throttle', 'api.env'])->group(function () {
    Route::get('networks', [CatalogController::class, 'networks'])->middleware('api.scope:services:read');
    Route::get('services', [CatalogController::class, 'services'])->middleware('api.scope:services:read');

    Route::get('products', [CatalogController::class, 'products'])->middleware('api.scope:products:read');
    Route::get('products/{offer}', [CatalogController::class, 'product'])
        ->where('offer', '(own|rsl)_[0-9]+')->middleware('api.scope:products:read');

    Route::post('quotes', [QuoteController::class, 'store'])->middleware('api.scope:quotes:create');

    Route::post('orders', [OrderController::class, 'store'])->middleware('api.scope:orders:create');
    Route::get('orders/{order}', [OrderController::class, 'show'])
        ->where('order', '(?:sbx_)?ord_[a-z0-9]{26}')->middleware('api.scope:orders:read');
    Route::get('orders/{order}/status', [OrderController::class, 'status'])
        ->where('order', '(?:sbx_)?ord_[a-z0-9]{26}')->middleware('api.scope:orders:read');
    Route::post('orders/{order}/payments', [PaymentController::class, 'store'])
        ->where('order', '(?:sbx_)?ord_[a-z0-9]{26}')->middleware('api.scope:payments:initiate');

    Route::get('transactions/{reference}', [PaymentController::class, 'transaction'])
        ->where('reference', '[A-Za-z0-9._\-]{6,120}')->middleware('api.scope:orders:read');

    // Sandbox-only. A production key is refused (403 ENVIRONMENT_MISMATCH) by api.sandbox before any
    // controller runs, and the loose id pattern means that refusal happens even for a malformed id
    // (a 404 from routing would otherwise say less than the truth).
    // Simulation needs its own dedicated scope, `sandbox:simulate` (sandbox keys only). `payments:initiate`
    // keeps meaning "start a payment" (POST /orders/{id}/payments) and grants no simulation access.
    Route::middleware('api.sandbox')->prefix('sandbox')->group(function () {
        Route::get('simulations', [SandboxController::class, 'scenarios'])->middleware('api.scope:sandbox:simulate');
        Route::post('orders/{order}/simulate', [SandboxController::class, 'simulate'])
            ->where('order', '[A-Za-z0-9_]{1,64}')->middleware('api.scope:sandbox:simulate');
    });
});
