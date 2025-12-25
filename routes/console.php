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

Artisan::command('xtra4u:mail-test {to : Recipient email address}', function () {
    $to = (string) $this->argument('to');

    try {
        \Illuminate\Support\Facades\Mail::raw(
            'XTRA4U SMTP test email. If you received this, mail configuration is working.',
            function ($message) use ($to) {
                $message->to($to)->subject('XTRA4U - SMTP Test');
            }
        );

        $this->info('Test email sent to: ' . $to);
        $this->line('Mailer: ' . (string) config('mail.default'));
        $this->line('From: ' . (string) config('mail.from.address'));
        $this->line('Host: ' . (string) config('mail.mailers.smtp.host'));

        return self::SUCCESS;
    } catch (\Throwable $e) {
        $this->error('Failed to send test email: ' . $e->getMessage());
        return self::FAILURE;
    }
})->purpose('Send a test email using the active (DB or fallback) SMTP configuration');

Artisan::command('xtra4u:debug-gateway-users {--force : Allow running outside local} {--gateway= : Filter by gateway_name (e.g. moolre)} {--reveal : Show full api_user/account_number (still hides keys)}', function () {
    if (! app()->environment('local') && ! $this->option('force')) {
        $this->error('Refusing to run outside local environment. Pass --force to override.');
        return self::FAILURE;
    }

    $reveal = (bool) $this->option('reveal');

    $mask = function (?string $value, int $keepEnd = 4) use (&$reveal): string {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return '(empty)';
        }

        if ($reveal) {
            return $value;
        }

        $len = strlen($value);
        if ($len <= $keepEnd) {
            return str_repeat('*', $len);
        }

        return str_repeat('*', $len - $keepEnd) . substr($value, -$keepEnd);
    };

    $bool = fn ($v) => $v ? 'yes' : 'no';

    $this->info('Default gateways');
    $defaultCollection = \App\Models\PaymentGatewayConfig::getDefault(\App\Models\PaymentGatewayConfig::TYPE_PAYMENT_COLLECTION);
    $defaultPayout = \App\Models\PaymentGatewayConfig::getDefault(\App\Models\PaymentGatewayConfig::TYPE_PAYOUT);

    $this->line('Collection: ' . ($defaultCollection ? ($defaultCollection->gateway_name . ' (id ' . $defaultCollection->id . ')') : '(none)'));
    $this->line('Payout: ' . ($defaultPayout ? ($defaultPayout->gateway_name . ' (id ' . $defaultPayout->id . ')') : '(none)'));
    $this->newLine();

    $query = \App\Models\PaymentGatewayConfig::query();
    $gateway = (string) ($this->option('gateway') ?? '');
    if ($gateway !== '') {
        $query->where('gateway_name', $gateway);
    }

    $rows = $query->orderBy('gateway_type')->orderByDesc('is_default')->get();
    if ($rows->isEmpty()) {
        $this->warn('No gateway configs found for the given filter.');
        return self::SUCCESS;
    }

    $this->info('Gateway configs (masked)');
    foreach ($rows as $cfg) {
        $data = (array) ($cfg->config_data ?? []);
        $apiUser = isset($data['api_user']) ? (string) $data['api_user'] : '';
        $accountNumber = isset($data['account_number']) ? (string) $data['account_number'] : '';

        $this->line(str_repeat('-', 60));
        $this->line('id: ' . $cfg->id);
        $this->line('gateway: ' . (string) $cfg->gateway_name);
        $this->line('type: ' . (string) $cfg->gateway_type);
        $this->line('env: ' . (string) ($cfg->environment ?? ''));
        $this->line('active: ' . $bool($cfg->is_active) . ' | default: ' . $bool($cfg->is_default));
        $this->line('api_user: ' . $mask($apiUser, 6));
        $this->line('account_number: ' . $mask($accountNumber, 4));

        // Do NOT print secrets; only indicate presence.
        $this->line('has_public_key: ' . $bool(! empty($data['public_key'])));
        $this->line('has_secret_key: ' . $bool(! empty($data['secret_key'])));
        $this->line('has_api_key: ' . $bool(! empty($data['api_key'])));
    }
    $this->line(str_repeat('-', 60));

    return self::SUCCESS;
})->purpose('Debug gateway api_user/account_number values (masked) for local troubleshooting');

Artisan::command('xtra4u:test-moolre-payout-auth {receiver : Phone number (e.g. 0559275699)} {--network=MTN : MTN|TELECEL|AirtelTigo} {--force : Allow running outside local}', function () {
    if (! app()->environment('local') && ! $this->option('force')) {
        $this->error('Refusing to run outside local environment. Pass --force to override.');
        return self::FAILURE;
    }

    $receiverInput = (string) $this->argument('receiver');
    $network = strtoupper((string) ($this->option('network') ?? 'MTN'));

    $networkCodes = [
        'MTN' => '1',
        'TELECEL' => '6',
        'AIRTELTIGO' => '7',
    ];

    if (!isset($networkCodes[$network])) {
        $this->error('Invalid --network. Allowed: MTN, TELECEL, AirtelTigo');
        return self::FAILURE;
    }

    $formatPhone = function (string $phone): string {
        $phone = preg_replace('/[^0-9]/', '', $phone);

        if (str_starts_with($phone, '0')) {
            $phone = '233' . substr($phone, 1);
        }

        if (!str_starts_with($phone, '233')) {
            $phone = '233' . $phone;
        }

        return $phone;
    };

    $mask = function (?string $value, int $keepEnd = 4): string {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return '(empty)';
        }

        $len = strlen($value);
        if ($len <= $keepEnd) {
            return str_repeat('*', $len);
        }

        return str_repeat('*', $len - $keepEnd) . substr($value, -$keepEnd);
    };

    $config = \App\Models\PaymentGatewayConfig::getDefault(\App\Models\PaymentGatewayConfig::TYPE_PAYOUT);
    if (!$config || !$config->is_active) {
        $this->error('No active payout gateway configured.');
        return self::FAILURE;
    }

    if ($config->gateway_name !== \App\Models\PaymentGatewayConfig::GATEWAY_MOOLRE) {
        $this->error('Default payout gateway is not moolre (found: ' . (string) $config->gateway_name . ').');
        return self::FAILURE;
    }

    if (!$config->isConfigured()) {
        $this->error('Moolre payout gateway is not fully configured (missing required fields).');
        return self::FAILURE;
    }

    $baseUrl = rtrim((string) $config->getConfig('base_url', 'https://api.moolre.com'), '/');
    $apiUser = trim((string) $config->getConfig('api_user', ''));
    $apiKey = trim((string) $config->getConfig('api_key', ''));
    $accountNumber = trim((string) $config->getConfig('account_number', ''));
    $currency = trim((string) $config->getConfig('currency', 'GHS'));

    $payload = [
        'type' => 1,
        'receiver' => $formatPhone($receiverInput),
        'channel' => $networkCodes[$network],
        'sublistid' => '',
        'currency' => $currency,
        'accountnumber' => $accountNumber,
    ];

    $headers = [
        'X-API-USER' => $apiUser,
        'X-API-KEY' => $apiKey,
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ];

    $this->info('Moolre payout auth test (validate)');
    $this->line('Gateway config id: ' . $config->id);
    $this->line('Base URL: ' . $baseUrl);
    $this->line('Endpoint: POST /open/transact/validate');
    $this->line('api_user: ' . ($apiUser !== '' ? $apiUser : '(empty)'));
    $this->line('api_key: ' . $mask($apiKey, 4) . ' (len ' . strlen($apiKey) . ')');
    $this->line('account_number: ' . $mask($accountNumber, 4));
    $this->newLine();

    $this->info('Payload');
    $this->line(json_encode($payload, JSON_PRETTY_PRINT));
    $this->newLine();

    try {
        $client = \Illuminate\Support\Facades\Http::withHeaders($headers)
            ->timeout(30)
            ->retry(0, 0);

        if (app()->environment('local', 'development', 'testing')) {
            $client = $client->withOptions(['verify' => false]);
        }

        $response = $client->post($baseUrl . '/open/transact/validate', $payload);
        $json = $response->json();

        $this->info('Response');
        $this->line('HTTP status: ' . $response->status());
        $this->line(json_encode($json, JSON_PRETTY_PRINT));

        // Return failure exit code if auth failed (status != 1)
        if (!$response->successful() || (int) ($json['status'] ?? 0) !== 1) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    } catch (\Throwable $e) {
        $this->error('Exception: ' . $e->getMessage());
        return self::FAILURE;
    }
})->purpose('Call Moolre /open/transact/validate using current DB payout config and print response');
