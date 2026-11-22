<?php

declare(strict_types=1);

return [
    // TP1: sample homes (with licensed photos) on every website until its MLS board is live. Off in tests.
    'sample_homes' => (bool) env('LISTINGS_SAMPLE_HOMES', true),
    // Where listing photos are kept (S3 behind the image CDN in production) and the public base URL.
    'media_disk' => env('LISTINGS_MEDIA_DISK', 'public'),
    'cdn_url' => env('LISTINGS_CDN_URL'),
    // Photo sizes (width in pixels) saved as WebP.
    'sizes' => ['thumb' => 400, 'card' => 800, 'hero' => 1600],
    'max_photo_bytes' => 25 * 1024 * 1024,
];
