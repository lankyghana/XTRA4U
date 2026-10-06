<?php

return [
    /*
    | A vendor reply to a Resolved conversation reopens it only if it was resolved
    | within this many days. Older Resolved conversations, and any Closed
    | conversation, require a new support request.
    */
    'reopen_days' => (int) env('SUPPORT_REOPEN_DAYS', 7),

    // Private disk (never the public disk). Files are served only via authorized routes.
    'disk' => env('SUPPORT_DISK', 'local'),

    'message_max_length' => 4000,
    'messages_per_page' => 50,
    'inbox_per_page' => 25,
    'poll_interval_seconds' => 8,

    'images' => [
        'max_per_message' => 4,
        'max_kb' => 5120,
        'max_pixels' => 40_000_000,
        'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
    ],

    'audio' => [
        'max_kb' => 5120,
        'max_seconds' => 180,
    ],
];
