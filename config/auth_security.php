<?php

return [
    'access_token_ttl_minutes' => (int) env('AUTH_ACCESS_TOKEN_TTL_MINUTES', 15),
    'refresh_token_ttl_minutes' => (int) env('AUTH_REFRESH_TOKEN_TTL_MINUTES', 43200),
    'max_login_attempts' => (int) env('AUTH_MAX_LOGIN_ATTEMPTS', 5),
    'lockout_seconds' => (int) env('AUTH_LOCKOUT_SECONDS', 900),
    'cookie_domain' => env('AUTH_COOKIE_DOMAIN'),
    'cookie_same_site' => env('AUTH_COOKIE_SAME_SITE', 'lax'),
];
