<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class DynamicMailServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // No services to register
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        try {
            // Ensure database connection is available
            DB::connection()->getPdo();

            // Check table existence safely
            if (!Schema::hasTable('settings')) {
                return;
            }

            $mailSettings = Setting::getGroup('email');

            if (empty($mailSettings)) {
                return;
            }

            // Set mailer
            $mailer = $mailSettings['mail_mailer'] ?? 'smtp';
            Config::set('mail.default', $mailer);

            // Configure SMTP settings
            if ($mailer === 'smtp') {
                Config::set('mail.mailers.smtp.host', $mailSettings['mail_host'] ?? '');
                Config::set('mail.mailers.smtp.port', (int) ($mailSettings['mail_port'] ?? 587));
                Config::set('mail.mailers.smtp.username', $mailSettings['mail_username'] ?? '');
                Config::set('mail.mailers.smtp.password', $mailSettings['mail_password'] ?? '');
                Config::set('mail.mailers.smtp.encryption', $mailSettings['mail_encryption'] ?? 'tls');
            }

            // From address
            Config::set(
                'mail.from.address',
                $mailSettings['mail_from_address'] ?? 'noreply@example.com'
            );

            Config::set(
                'mail.from.name',
                $mailSettings['mail_from_name'] ?? config('app.name')
            );
        } catch (\Throwable $e) {
            // Silently ignore DB / mail config errors during boot
            return;
        }
    }
}
