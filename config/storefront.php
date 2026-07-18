<?php

return [
    // When enabled, GET /checkout renders a Coming Soon page instead of the marketplace.
    // Toggle via .env: CHECKOUT_COMING_SOON=true
    'checkout_coming_soon' => (bool) env('CHECKOUT_COMING_SOON', false),

    // Vendor code of the platform's flagship store. Public entry points that
    // are not tied to a specific vendor (homepage "Buy Now", the Results
    // Checker link in the main navigation) send customers to this store.
    'main_store_code' => env('STOREFRONT_MAIN_STORE_CODE', 'BUNDJXW6SR'),

    'default_category' => 'data',
    'categories' => [
        'data' => [
            'label' => 'Data Bundles',
            'icon' => 'signal',
            'description' => 'Browse airtime, data, and bundle packages.',
        ],
        'ecg' => [
            'label' => 'ECG',
            'icon' => 'bolt',
            'description' => 'Pay electricity tokens and prepaid bills.',
        ],
        'shop' => [
            'label' => 'Shop Online',
            'icon' => 'bag',
            'description' => 'Purchase lifestyle vouchers and digital goods.',
        ],
        'results' => [
            'label' => 'Results Checkers',
            'icon' => 'chart',
            'description' => 'Access exam results and status updates.',
        ],
        'afa' => [
            'label' => 'AFA Registration',
            'icon' => 'clipboard',
            'description' => 'Register for AFA (Agricultural Finance Authority) services.',
        ],
    ],
];
