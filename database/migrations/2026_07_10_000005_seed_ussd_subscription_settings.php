<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Adds the settings the subscription model needs. Every key seeded by
     * 2026_06_27_120000_seed_ussd_settings is left untouched, so the existing
     * admin form and UssdMenuService keep reading exactly what they read today.
     */
    public function up(): void
    {
        $defaults = [
            // Dialled prefix assigned by the provider. The legacy
            // `ussd_service_code` key is display-only and stays for reference.
            ['key' => 'ussd_base_code',                'value' => '*203*',  'group' => 'ussd'],
            ['key' => 'ussd_provider',                 'value' => 'moolre', 'group' => 'ussd'],

            // 240s preserves UssdSessionService::SESSION_TIMEOUT_MINUTES (4).
            ['key' => 'ussd_session_timeout_seconds',  'value' => '240',    'group' => 'ussd'],
            ['key' => 'ussd_max_requests_per_session', 'value' => '20',     'group' => 'ussd'],
            ['key' => 'ussd_max_retry_attempts',       'value' => '3',      'group' => 'ussd'],

            // Gateway authentication for POST /api/ussd. Empty allowlist means
            // "no IP restriction"; the secret is stored encrypted by the admin
            // controller and is never rendered back to the browser.
            ['key' => 'ussd_gateway_ip_allowlist',     'value' => '',       'group' => 'ussd'],
            ['key' => 'ussd_gateway_secret',           'value' => '',       'group' => 'ussd'],
        ];

        foreach ($defaults as $row) {
            DB::table('settings')->insertOrIgnore($row + [
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'ussd_base_code',
            'ussd_provider',
            'ussd_session_timeout_seconds',
            'ussd_max_requests_per_session',
            'ussd_max_retry_attempts',
            'ussd_gateway_ip_allowlist',
            'ussd_gateway_secret',
        ])->delete();
    }
};
