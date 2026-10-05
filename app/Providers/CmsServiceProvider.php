<?php

namespace App\Providers;

use App\Support\Cms\CmsContent;
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
    }
}
