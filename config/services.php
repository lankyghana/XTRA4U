<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mail (Fallback)
    |--------------------------------------------------------------------------
    |
    | These values are the safe fallback defaults (typically sourced from .env)
    | used when database-driven email settings are not configured.
    |
    | NOTE: config/mail.php must not rely on env('MAIL_*') directly.
    */

    'mail_fallback' => [
        'mailer' => env('MAIL_MAILER', 'log'),
        'url' => env('MAIL_URL'),
        'scheme' => env('MAIL_SCHEME'),
        'host' => env('MAIL_HOST', '127.0.0.1'),
        'port' => env('MAIL_PORT', 2525),
        'username' => env('MAIL_USERNAME'),
        'password' => env('MAIL_PASSWORD'),
        'ehlo_domain' => env('MAIL_EHLO_DOMAIN'),
        'sendmail_path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        'log_channel' => env('MAIL_LOG_CHANNEL'),
        'from' => [
            'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
            'name' => env('MAIL_FROM_NAME', 'Example'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'bulkclix' => [
        'api_key' => env('BULKCLIX_API_KEY'),
        'sender_id' => env('BULKCLIX_SENDER_ID', 'XTRA4U'),
        'base_url' => env('BULKCLIX_BASE_URL', 'https://api.bulkclix.com/api/v1'),
    ],

    'paystack' => [
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'payment_url' => env('PAYSTACK_PAYMENT_URL', 'https://api.paystack.co'),
    ],

    'external_fulfillment' => [
        // Configured by the system administrator (.env). Not editable from the admin UI.
        'base_url' => env('EXTERNAL_FULFILLMENT_BASE_URL', ''),
        'endpoint' => env('EXTERNAL_FULFILLMENT_ENDPOINT', ''),
        'services_endpoint' => env('EXTERNAL_FULFILLMENT_SERVICES_ENDPOINT', '/services'),
    ],

    'xpresportal' => [
        'base_url' => env('XPRES_BASE_URL'),
        'services_endpoint' => env('XPRES_SERVICES_ENDPOINT', '/api/v1/services'),
        'api_key' => env('XPRES_API_KEY'),
        'api_secret' => env('XPRES_API_SECRET'),
        'timeout' => env('XPRES_TIMEOUT', 30),
        'environment' => env('XPRES_ENV', 'sandbox'),
    ],

    'gigshub' => [
        'base_url' => env('GIGSHUB_BASE_URL'),
        'api_key' => env('GIGSHUB_API_KEY'),
        'timeout' => env('GIGSHUB_TIMEOUT', 30),
    ],

];
