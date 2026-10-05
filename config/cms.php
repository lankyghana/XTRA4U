<?php

return [
    /*
    | Limits for the CMS media library. Uploads are decoded and re-encoded
    | server-side, so these also bound the memory a decode can use.
    */
    'media' => [
        'max_kb' => (int) env('CMS_MEDIA_MAX_KB', 5120),
        'min_dimension' => 32,
        'max_dimension' => 5000,
        'max_megapixels' => 16,
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
        'directory' => 'cms',
    ],
];
