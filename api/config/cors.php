<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // CORS_ALLOWED_ORIGINS: comma-separated origins (the web app, and the
    // POS web preview locally); defaults to FRONTEND_URL alone.
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://localhost:3008'))),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // The web app names CSV downloads from Content-Disposition (RBAC-11).
    'exposed_headers' => ['Content-Disposition'],

    // NFR-02: the web app and the API are on different hosts, so every call
    // with an Authorization header needs a CORS preflight. Browsers may reuse
    // a preflight answer for this many seconds (Chrome caps it at 7200); with
    // 0 they asked again before nearly every request, doubling round trips.
    'max_age' => (int) env('CORS_MAX_AGE', 7200),

    // Bearer tokens only: no cookies cross origins.
    'supports_credentials' => false,

];
