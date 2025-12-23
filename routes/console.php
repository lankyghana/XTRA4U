<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('xtra4u:ensure-admin {email} {--password=} {--force}', function () {
    $email = (string) $this->argument('email');
    $password = (string) ($this->option('password') ?? '');

    if (! app()->environment('local') && ! $this->option('force')) {
        $this->error('Refusing to run outside local environment. Pass --force to override.');
        return self::FAILURE;
    }

    if ($password === '') {
        $this->error('Missing required option: --password');
        return self::FAILURE;
    }

    /** @var \App\Models\User $user */
    $user = \App\Models\User::updateOrCreate(
        ['email' => $email],
        [
            'name' => 'System Administrator',
            'password' => bcrypt($password),
            'role' => 'admin',
            'email_verified_at' => now(),
        ]
    );

    // Keep the admins table in sync if it exists / is used elsewhere.
    \App\Models\Admin::updateOrCreate(
        ['email' => $email],
        [
            'name' => 'System Administrator',
            'password' => bcrypt($password),
        ]
    );

    $this->info('Admin login ensured.');
    $this->line('Email: ' . $email);
    $this->line('Password: ' . str_repeat('*', min(12, strlen($password))));
    $this->line('Role: ' . ($user->role ?? '')); 

    return self::SUCCESS;
})->purpose('Create/reset a local admin user for the admin portal');

// Schedule cleanup of abandoned payments every 6 hours
Schedule::command('payments:cleanup --hours=24')->everySixHours();

// Retry any withdrawals that are stuck in processing (safe + idempotent)
Schedule::command('withdrawals:retry-stuck --minutes=10 --limit=200')->everyFiveMinutes();
