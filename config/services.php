<?php

return [

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
        'base_url' => env('BULKCLIX_BASE_URL', 'https://bulkclix.com/api/v1'),
    ],


    'paystack' => [
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'payment_url' => env('PAYSTACK_PAYMENT_URL', 'https://api.paystack.co'),
    ],

    'moolre' => [
        'api_key' => env('MOOLRE_API_KEY'),
        'api_pubkey' => env('MOOLRE_API_PUBKEY'),
        'api_user' => env('MOOLRE_API_USER'),
        'account_number' => env('MOOLRE_ACCOUNT_NUMBER'),
        'base_url' => env('MOOLRE_BASE_URL', 'https://api.moolre.com'),
    ],


];
