<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

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

        try {
            $mailSettings = Setting::getGroup('email');

            if (!empty($mailSettings)) {
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

                // Set from address
                Config::set('mail.from.address', $mailSettings['mail_from_address'] ?? 'noreply@example.com');
                Config::set('mail.from.name', $mailSettings['mail_from_name'] ?? config('app.name'));
            }
        } catch (\Exception $e) {
            // Silently fail if database is not available
        }
    }
}
