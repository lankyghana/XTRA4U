<?php

use App\Http\Controllers\CmsPageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CMS routes (loaded from routes/web.php, so the `web` group - sessions,
| CSRF verification - applies to everything below)
|--------------------------------------------------------------------------
*/

// ---- Public, read-only. Published content only.
Route::get('/faq', [CmsPageController::class, 'faq'])->name('cms.faq');
Route::get('/p/{slug}', [CmsPageController::class, 'show'])
    ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('cms.page');
