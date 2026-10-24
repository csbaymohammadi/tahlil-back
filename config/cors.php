<?php

return [
    'paths' => ['api/v1/admin/*', 'media/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => ['*'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['X-Request-ID', 'Retry-After', 'Location', 'Content-Range', 'Accept-Ranges', 'Content-Length', 'Content-Disposition'],
    'max_age' => 600,
    'supports_credentials' => true,
];
