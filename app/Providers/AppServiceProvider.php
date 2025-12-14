<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Schema::defaultStringLength(191);
        if (config('app.env') === 'production' || config('app.url') && str_starts_with(config('app.url'), 'https://')) {
            \URL::forceScheme('https');
        }
    }
}