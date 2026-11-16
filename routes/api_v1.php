<?php

use App\Http\Controllers\DeveloperApi\CatalogController;
use App\Http\Controllers\DeveloperApi\HealthController;
use App\Http\Controllers\DeveloperApi\OrderController;
use App\Http\Controllers\DeveloperApi\PaymentController;
use App\Http\Controllers\DeveloperApi\QuoteController;
use Illuminate\Support\Facades\Route;

/*
| Public Developer API, mounted at /api/v1 (see bootstrap/app.php). Stateless:
| no session, no CSRF, no cookies. Every route except /health requires an API key.
*/

Route::get('health', HealthController::class)->middleware('throttle:60,1')->name('health');

// api.production: until the sandbox engine serves these routes, sandbox keys are refused (fail closed).
Route::middleware(['api.key', 'api.throttle', 'api.production'])->group(function () {
    Route::get('networks', [CatalogController::class, 'networks'])->middleware('api.scope:services:read');
    Route::get('services', [CatalogController::class, 'services'])->middleware('api.scope:services:read');

    Route::get('products', [CatalogController::class, 'products'])->middleware('api.scope:products:read');
    Route::get('products/{offer}', [CatalogController::class, 'product'])
        ->where('offer', '(own|rsl)_[0-9]+')->middleware('api.scope:products:read');

    Route::post('quotes', [QuoteController::class, 'store'])->middleware('api.scope:quotes:create');

    Route::post('orders', [OrderController::class, 'store'])->middleware('api.scope:orders:create');
    Route::get('orders/{order}', [OrderController::class, 'show'])
        ->where('order', 'ord_[a-z0-9]{26}')->middleware('api.scope:orders:read');
    Route::get('orders/{order}/status', [OrderController::class, 'status'])
        ->where('order', 'ord_[a-z0-9]{26}')->middleware('api.scope:orders:read');
    Route::post('orders/{order}/payments', [PaymentController::class, 'store'])
        ->where('order', 'ord_[a-z0-9]{26}')->middleware('api.scope:payments:initiate');

    Route::get('transactions/{reference}', [PaymentController::class, 'transaction'])
        ->where('reference', '[A-Za-z0-9._\-]{6,120}')->middleware('api.scope:orders:read');
});
