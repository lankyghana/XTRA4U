<?php

namespace App\Support\Mail;

use App\Models\Setting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class DynamicMailConfigurator
{
    /**
     * Apply email settings from the database at runtime.
     *
     * Safe behavior:
     * - Never writes to .env
     * - If DB/table/settings are missing, keeps existing config (fallback from config/services.php)
     */
    public function apply(bool $fresh = false): void
    {
        if (!Schema::hasTable('settings')) {
            return;
        }

        try {
            $mailSettings = $fresh
                ? Setting::getGroupFresh('email')
                : Setting::getGroup('email');

            if (empty($mailSettings)) {
                return;
            }

            $mailer = (string) ($mailSettings['mail_mailer'] ?? 'smtp');
            if (!in_array($mailer, ['smtp', 'sendmail', 'log'], true)) {
                $mailer = 'smtp';
            }

            Config::set('mail.default', $mailer);

            if ($mailer === 'smtp') {
                $host = trim((string) ($mailSettings['mail_host'] ?? ''));
                $port = (int) ($mailSettings['mail_port'] ?? 587);
                $username = (string) ($mailSettings['mail_username'] ?? '');
                $password = (string) ($mailSettings['mail_password'] ?? '');
                $encryption = $mailSettings['mail_encryption'] ?? 'tls';

                // Only apply SMTP if host/port look valid; otherwise keep fallback.
                if ($host !== '' && $port > 0) {
                    Config::set('mail.mailers.smtp.host', $host);
                    Config::set('mail.mailers.smtp.port', $port);

                    // Keep URL null to avoid overriding discrete host/port settings.
                    Config::set('mail.mailers.smtp.url', null);

                    Config::set('mail.mailers.smtp.username', $username !== '' ? $username : null);
                    Config::set('mail.mailers.smtp.password', $password !== '' ? $password : null);

                    // Laravel 12 uses Symfony Mailer: scheme controls TLS/SSL.
                    $scheme = match ($encryption) {
                        'ssl' => 'smtps',
                        'tls' => 'tls',
                        'null', null, '' => null,
                        default => 'tls',
                    };
                    Config::set('mail.mailers.smtp.scheme', $scheme);

                    $localDomain = config('services.mail_fallback.ehlo_domain')
                        ?? parse_url((string) config('app.url', 'http://localhost'), PHP_URL_HOST);
                    Config::set('mail.mailers.smtp.local_domain', $localDomain);
                }
            }

            $fromAddress = trim((string) ($mailSettings['mail_from_address'] ?? ''));
            $fromName = trim((string) ($mailSettings['mail_from_name'] ?? ''));

            if ($fromAddress !== '') {
                Config::set('mail.from.address', $fromAddress);
            }
            if ($fromName !== '') {
                Config::set('mail.from.name', $fromName);
            }
        } catch (\Throwable $e) {
            // Production-safe: do not crash requests/queues due to email config.
            Log::warning('Dynamic mail configuration failed; using fallback mail settings.', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
