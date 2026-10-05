<?php

namespace App\Providers;

use App\Support\Cms\CmsContent;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class CmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One instance per request so preview mode and per-request memoisation are shared.
        $this->app->singleton(CmsContent::class);
    }

    public function boot(): void
    {
        // Public views read content through `$cms`; the object makes no query until used.
        View::share('cms', $this->app->make(CmsContent::class));

        // Preview mode and memoised lookups are per request; never let them outlive it.
        Event::listen(RequestHandled::class, fn () => $this->app->make(CmsContent::class)->flush());
    }
}
