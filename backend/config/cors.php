<?php

$frontendOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', env('FRONTEND_URLS', env('FRONTEND_URL', 'http://localhost:5173')))
)));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/auth'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $frontendOrigins ?: ['http://localhost:5173'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['X-Request-ID'],
    'max_age' => 0,
    'supports_credentials' => true,
];
