<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule cleanup of abandoned payments every 6 hours
Schedule::command('payments:cleanup --hours=24')->everySixHours();

// Schedule cleanup of pending orders older than 24 hours (delete unsuccessful payments)
// Runs daily at 2 AM
Schedule::command('orders:cleanup-pending')->dailyAt('02:00');
