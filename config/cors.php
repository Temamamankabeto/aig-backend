<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Configure your settings for cross-origin requests (CORS).
    | These determine what operations may be executed in browsers.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'], // Allow all HTTP methods (GET, POST, etc.)

    'allowed_origins' => [
        'http://localhost:3000',
        'http://192.168.2.1:3000',
        'https://cafeaig.vercel.app',
        'https://aigcafe.com',
        'https://www.aigcafe.com',
        'https://aigcafeapi.ofijan.com',
    ],

    'allowed_origins_patterns' => [
        'https://*.aigcafe.com',
        'https://*.ofijan.com',
    ],

    'allowed_headers' => ['*'], // Allow all headers (e.g., Content-Type, X-XSRF-TOKEN)

    'exposed_headers' => [
        'Authorization',
        'X-CSRF-TOKEN',
        'X-Requested-With',
    ],

    'max_age' => 3600, // Cache preflight responses for 1 hour

    'supports_credentials' => true, // Required for cookie-based auth like Laravel Sanctum

];