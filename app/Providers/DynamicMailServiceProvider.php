<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use App\Support\Mail\DynamicMailConfigurator;

class DynamicMailServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Only configure if table exists (avoid errors during migration)
        if (!Schema::hasTable('settings')) {
            return;
        }

        // Delegate to the shared configurator.
        app(DynamicMailConfigurator::class)->apply(true);
    }
}
