<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Mobile Money (MoMo) UI/validation definitions
    |--------------------------------------------------------------------------
    |
    | Keep network labels + Tailwind styling tokens centralized so that
    | checkout, withdrawals, admin screens, and validation stay in sync.
    |
    */

    // Used for vendor withdrawals (momo_network stored on VendorWithdrawal)
    'withdrawal_networks' => [
        'MTN' => [
            'label' => 'MTN',
            'radio' => [
                'peer_checked_border' => 'peer-checked:border-yellow-500',
                'peer_checked_bg' => 'peer-checked:bg-yellow-50',
                'badge_bg' => 'bg-yellow-400',
                'badge_text' => 'text-yellow-900',
                'badge_label' => 'MTN',
            ],
            'history' => [
                'badge_class' => 'bg-yellow-400 text-yellow-900',
                'badge_label' => 'M',
            ],
            'admin' => [
                'badge_class' => 'bg-yellow-400 text-yellow-900',
                'badge_label' => 'MTN',
            ],
        ],
        'TELECEL' => [
            'label' => 'Telecel',
            'radio' => [
                'peer_checked_border' => 'peer-checked:border-red-500',
                'peer_checked_bg' => 'peer-checked:bg-red-50',
                'badge_bg' => 'bg-red-500',
                'badge_text' => 'text-white',
                'badge_label' => 'TEL',
            ],
            'history' => [
                'badge_class' => 'bg-red-500 text-white',
                'badge_label' => 'T',
            ],
            'admin' => [
                'badge_class' => 'bg-red-500 text-white',
                'badge_label' => 'TEL',
            ],
        ],
        'AirtelTigo' => [
            'label' => 'AirtelTigo',
            'radio' => [
                'peer_checked_border' => 'peer-checked:border-blue-500',
                'peer_checked_bg' => 'peer-checked:bg-blue-50',
                'badge_bg' => 'bg-gradient-to-r from-red-500 to-blue-500',
                'badge_text' => 'text-white',
                'badge_label' => 'AT',
            ],
            'history' => [
                'badge_class' => 'bg-gradient-to-r from-red-500 to-blue-500 text-white',
                'badge_label' => 'A',
            ],
            'admin' => [
                'badge_class' => 'bg-gradient-to-r from-red-500 to-blue-500 text-white',
                'badge_label' => 'AT',
            ],
        ],
    ],

    // Used for checkout marketplace filtering + styling (product.network values)
    'product_networks' => [
        'MTN' => [
            'label' => 'MTN',
            'gradient' => 'from-yellow-400 to-yellow-500',
        ],
        'Vodafone' => [
            'label' => 'Vodafone',
            'gradient' => 'from-red-500 to-red-600',
        ],
        'TELECEL' => [
            'label' => 'TELECEL',
            'gradient' => 'from-red-500 to-red-600',
        ],
        'AirtelTigo' => [
            'label' => 'AirtelTigo',
            'gradient' => 'from-blue-500 to-blue-600',
        ],
    ],

    // Used for vendor payout settings (Vendor::momo_provider values)
    'providers' => [
        'mtn' => 'MTN Mobile Money',
        'vodafone' => 'Vodafone Cash',
        'airteltigo' => 'AirtelTigo Money',
    ],
];
