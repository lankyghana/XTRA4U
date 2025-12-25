<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Queue;
use Illuminate\Queue\Events\JobProcessing;
use App\Support\Mail\DynamicMailConfigurator;

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

		// Apply database-driven SMTP configuration (fallback stays in config/services.php).
		app(DynamicMailConfigurator::class)->apply();

		// Queue workers are long-running; refresh mail config before each job
		// so changes in Admin Email Settings take effect without restarting workers.
		Queue::before(function (JobProcessing $event): void {
			app(DynamicMailConfigurator::class)->apply(true);
		});
    }
}