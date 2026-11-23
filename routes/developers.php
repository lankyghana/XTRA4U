<?php

use App\Http\Controllers\Developers\ApiKeyController;
use App\Http\Controllers\Developers\ApplicationController;
use App\Http\Controllers\Developers\AssistantController;
use App\Http\Controllers\Developers\DashboardController;
use App\Http\Controllers\Developers\DocsController;
use App\Http\Controllers\Developers\LandingController;
use App\Http\Controllers\Developers\WebhookController;
use App\Services\DeveloperApi\Docs\PortalDocs;
use Illuminate\Support\Facades\Route;

/*
| Developer Portal. Loaded in the `web` middleware group (session, CSRF, CSP) from bootstrap/app.php.
|
| There is NO developer registration or login. The portal recognises the vendor signed in through the
| platform's existing `vendor` guard (existing registration, existing login, existing admin approval).
| `/developers` itself is a public information page; everything else needs an APPROVED vendor.
*/
Route::prefix('developers')->name('developers.')->group(function () {
    Route::get('/', LandingController::class)->name('landing');

    Route::middleware('developer.access')->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        // API documentation. Same gate as the rest of the portal; static, allowlisted pages (see PortalDocs).
        Route::get('docs', [DocsController::class, 'index'])->name('docs.index');
        Route::get('docs/{page}', [DocsController::class, 'show'])->where('page', PortalDocs::SLUG_PATTERN)->name('docs.show');

        // Developer Assistant (M5.4): read-only, stateless, JSON-only. Same vendor guard and live approval check as
        // everything else here; POST is CSRF-protected (web group) and throttled per vendor. No history endpoint exists.
        Route::get('assistant', [AssistantController::class, 'show'])->name('assistant');
        Route::post('assistant/messages', [AssistantController::class, 'message'])->middleware('throttle:developer-assistant')->name('assistant.message');

        Route::get('applications', [ApplicationController::class, 'index'])->name('applications.index');
        Route::get('applications/create', [ApplicationController::class, 'create'])->name('applications.create');
        Route::post('applications', [ApplicationController::class, 'store'])->middleware('throttle:20,1')->name('applications.store');

        Route::prefix('applications/{application}')->whereNumber('application')->group(function () {
            Route::get('/', [ApplicationController::class, 'show'])->name('applications.show');
            Route::put('/', [ApplicationController::class, 'update'])->name('applications.update');
            Route::delete('/', [ApplicationController::class, 'destroy'])->name('applications.destroy');
            Route::get('requests', [ApplicationController::class, 'requests'])->name('applications.requests');

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
