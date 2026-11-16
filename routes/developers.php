<?php

use App\Http\Controllers\Developers\ApiKeyController;
use App\Http\Controllers\Developers\ApplicationController;
use App\Http\Controllers\Developers\AuthController;
use App\Http\Controllers\Developers\DashboardController;
use App\Http\Controllers\Developers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
| Developer portal. Loaded in the `web` middleware group (session, CSRF, CSP) from bootstrap/app.php.
| Own `developer` guard — a separate realm from customers, vendors and admins.
*/
Route::prefix('developers')->name('developers.')->group(function () {
    Route::get('/', [AuthController::class, 'landing'])->name('landing');

    Route::middleware('guest:developer')->group(function () {
        Route::get('register', [AuthController::class, 'showRegister'])->name('register');
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:5,1')->name('register.submit');
        Route::get('login', [AuthController::class, 'showLogin'])->name('login');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login.submit');
    });

    Route::post('logout', [AuthController::class, 'logout'])->name('logout');

    Route::middleware('developer.auth')->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        Route::get('applications', [ApplicationController::class, 'index'])->name('applications.index');
        Route::get('applications/create', [ApplicationController::class, 'create'])->name('applications.create');
        Route::post('applications', [ApplicationController::class, 'store'])->middleware('throttle:20,1')->name('applications.store');

        Route::prefix('applications/{application}')->whereNumber('application')->group(function () {
            Route::get('/', [ApplicationController::class, 'show'])->name('applications.show');
            Route::put('/', [ApplicationController::class, 'update'])->name('applications.update');
            Route::delete('/', [ApplicationController::class, 'destroy'])->name('applications.destroy');
            Route::post('production-request', [ApplicationController::class, 'requestProduction'])->name('applications.production-request');

            Route::post('keys', [ApiKeyController::class, 'store'])->middleware('throttle:20,1')->name('keys.store');
            Route::post('keys/{key}/rotate', [ApiKeyController::class, 'rotate'])->whereNumber('key')->middleware('throttle:20,1')->name('keys.rotate');
            Route::post('keys/{key}/revoke', [ApiKeyController::class, 'revoke'])->whereNumber('key')->name('keys.revoke');

            Route::get('webhooks', [WebhookController::class, 'index'])->name('webhooks.index');
            Route::post('webhooks', [WebhookController::class, 'store'])->middleware('throttle:20,1')->name('webhooks.store');
            Route::prefix('webhooks/{webhook}')->whereNumber('webhook')->group(function () {
                Route::put('/', [WebhookController::class, 'update'])->name('webhooks.update');
                Route::delete('/', [WebhookController::class, 'destroy'])->name('webhooks.destroy');
                Route::post('toggle', [WebhookController::class, 'toggle'])->name('webhooks.toggle');
                Route::post('rotate-secret', [WebhookController::class, 'rotateSecret'])->middleware('throttle:20,1')->name('webhooks.rotate-secret');
                Route::get('deliveries', [WebhookController::class, 'deliveries'])->name('webhooks.deliveries');
                Route::post('deliveries/{delivery}/retry', [WebhookController::class, 'retry'])->whereNumber('delivery')->middleware('throttle:30,1')->name('webhooks.retry');
            });
        });
    });
});
