<?php

use App\Http\Controllers\Admin\Cms;
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

// ---- Admin: Content. Every route requires BOTH the generic admin gate and the
// stricter CMS identity check (Admin on the admin guard, or web User with role=admin).
Route::middleware(['admin.only', 'cms.admin'])
    ->prefix('admin/content')
    ->name('admin.cms.')
    ->group(function () {
        Route::get('/', [Cms\CmsDashboardController::class, 'index'])->name('dashboard');

        // Rich pages (Privacy, Terms, any added page)
        Route::get('pages', [Cms\CmsPagesController::class, 'index'])->name('pages.index');
        Route::get('pages/create', [Cms\CmsPagesController::class, 'create'])->name('pages.create');
        Route::post('pages', [Cms\CmsPagesController::class, 'store'])->name('pages.store');
        Route::get('pages/{page}/edit', [Cms\CmsPagesController::class, 'edit'])->whereNumber('page')->name('pages.edit');
        Route::put('pages/{page}', [Cms\CmsPagesController::class, 'update'])->whereNumber('page')->name('pages.update');
        Route::post('pages/{page}/publish', [Cms\CmsPagesController::class, 'publish'])->whereNumber('page')->name('pages.publish');
        Route::post('pages/{page}/unpublish', [Cms\CmsPagesController::class, 'unpublish'])->whereNumber('page')->name('pages.unpublish');
        Route::post('pages/{page}/discard', [Cms\CmsPagesController::class, 'discard'])->whereNumber('page')->name('pages.discard');
        Route::get('pages/{page}/preview', [Cms\CmsPagesController::class, 'preview'])->whereNumber('page')->name('pages.preview');
        Route::get('pages/{page}/history', [Cms\CmsPagesController::class, 'history'])->whereNumber('page')->name('pages.history');
        Route::post('pages/{page}/restore/{revision}', [Cms\CmsPagesController::class, 'restore'])->whereNumber(['page', 'revision'])->name('pages.restore');
        Route::delete('pages/{page}', [Cms\CmsPagesController::class, 'archive'])->whereNumber('page')->name('pages.archive');
        Route::post('pages/{id}/unarchive', [Cms\CmsPagesController::class, 'unarchive'])->whereNumber('id')->name('pages.unarchive');
        Route::post('markdown-preview', [Cms\CmsPagesController::class, 'markdownPreview'])->middleware('throttle:60,1')->name('markdown-preview');

        // Structured pages: homepage + about
        Route::prefix('site/{key}')->where(['key' => 'home|about'])->group(function () {
            Route::get('/', [Cms\CmsStructuredPageController::class, 'edit'])->name('site.edit');
            Route::put('sections/{section}', [Cms\CmsStructuredPageController::class, 'saveSection'])->name('site.section');
            Route::post('sections/{section}/visibility', [Cms\CmsStructuredPageController::class, 'visibility'])->name('site.visibility');
            Route::post('sections/{section}/move', [Cms\CmsStructuredPageController::class, 'move'])->name('site.move');
            Route::put('seo', [Cms\CmsStructuredPageController::class, 'saveSeo'])->name('site.seo');
            Route::post('publish', [Cms\CmsStructuredPageController::class, 'publish'])->name('site.publish');
            Route::post('discard', [Cms\CmsStructuredPageController::class, 'discard'])->name('site.discard');
            Route::get('preview', [Cms\CmsStructuredPageController::class, 'preview'])->name('site.preview');
            Route::get('history', [Cms\CmsStructuredPageController::class, 'history'])->name('site.history');
            Route::post('restore/{revision}', [Cms\CmsStructuredPageController::class, 'restore'])->whereNumber('revision')->name('site.restore');
        });

        Route::resource('banners', Cms\CmsBannersController::class)->except(['show']);
        Route::resource('announcements', Cms\CmsAnnouncementsController::class)->except(['show']);
        Route::resource('faqs', Cms\CmsFaqsController::class)->except(['show']);
        Route::post('faqs/{faq}/move', [Cms\CmsFaqsController::class, 'move'])->name('faqs.move');
        Route::post('banners/{banner}/move', [Cms\CmsBannersController::class, 'move'])->name('banners.move');

        Route::get('media', [Cms\CmsMediaController::class, 'index'])->name('media.index');
        Route::get('media/picker', [Cms\CmsMediaController::class, 'picker'])->name('media.picker');
        Route::post('media', [Cms\CmsMediaController::class, 'store'])->middleware('throttle:30,1')->name('media.store');
        Route::put('media/{media}', [Cms\CmsMediaController::class, 'update'])->whereNumber('media')->name('media.update');
        Route::delete('media/{media}', [Cms\CmsMediaController::class, 'destroy'])->whereNumber('media')->name('media.destroy');

        Route::get('navigation', [Cms\CmsNavigationController::class, 'index'])->name('navigation.index');
        Route::put('navigation', [Cms\CmsNavigationController::class, 'update'])->name('navigation.update');

        Route::get('settings', [Cms\CmsSettingsController::class, 'edit'])->name('settings.edit');
        Route::put('settings', [Cms\CmsSettingsController::class, 'update'])->name('settings.update');
    });
