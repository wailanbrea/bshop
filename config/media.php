<?php

return [
    'provider' => env('MEDIA_PROVIDER', 'local'),
    'max_pixels' => 20000000,
    'max_dimension' => 20000,
    'quality' => 82,
    'renditions' => ['thumb' => 320, 'card' => 640, 'detail' => 1600],
    'lease_minutes' => 15,
    'retention_hours' => 24,
    'r2_public_url' => env('R2_PUBLIC_URL'),
];
