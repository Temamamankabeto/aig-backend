<?php

$configuredOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
)));

$defaultOrigins = array_values(array_filter([
    env('FRONTEND_URL'),
    'https://aigcafe.com',
    'https://www.aigcafe.com',
]));

if (env('APP_ENV', 'production') === 'local') {
    $defaultOrigins = array_merge($defaultOrigins, [
        'http://localhost:3000',
        'http://127.0.0.1:3000',
    ]);
}

$origins = array_values(array_unique(array_merge(
    $configuredOrigins,
    $defaultOrigins
)));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $origins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Origin',
        'X-Requested-With',
        'X-XSRF-TOKEN',
    ],
    'exposed_headers' => [],
    'max_age' => 3600,
    'supports_credentials' => true,
];
