<?php

return [
    // High-volume audit logging switches.
    // Safe default: enabled, but automatically skipped when queue driver is sync.
    'recipient_numbers' => [
        'enabled' => env('RECIPIENT_NUMBER_LOGGING_ENABLED', true),
        'queue' => env('RECIPIENT_NUMBER_LOGGING_QUEUE', 'audit'),
    ],
];
