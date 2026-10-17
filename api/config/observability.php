<?php

/**
 * NFR-01, NFR-07, NFR-10: health checks, metrics and the status snapshot.
 * See docs/observability.md.
 */
return [

    'metrics' => [
        // GET /metrics answers only a scraper that sends this bearer token,
        // or one whose address is in the allow-list (CIDRs, comma-separated,
        // e.g. the private network 192.168.128.0/17). With neither set the
        // endpoint is 404 for everyone. Never public.
        'token' => env('METRICS_TOKEN'),
        'allow' => array_values(array_filter(array_map('trim', explode(',', (string) env('METRICS_ALLOW', ''))))),

        // Where request counts and latency histograms are kept between
        // scrapes: `redis` (shared by every API host) or `array` (this
        // process only; tests).
        'store' => env('METRICS_STORE', 'redis'),
        'redis_connection' => env('METRICS_REDIS_CONNECTION', 'default'),

        // Latency histogram buckets, in seconds (NFR-02: p95 under 0.5 s).
        'buckets' => [0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5],
    ],

    'health' => [
        // The connection the readiness check pings (the runtime role).
        'database_connection' => env('HEALTH_DATABASE_CONNECTION', 'pgsql'),
        // The disk whose write and read the readiness check proves.
        'storage_disk' => env('HEALTH_STORAGE_DISK', 'media'),
        // Readiness is for the load balancer: a stopped Horizon does not take
        // the API out of rotation unless this is true (it still shows in
        // /metrics and the status snapshot).
        'require_horizon' => (bool) env('HEALTH_REQUIRE_HORIZON', false),
    ],

    'status' => [
        // `health:status` writes the public status snapshot here every minute.
        'disk' => env('STATUS_DISK', 'local'),
        'path' => env('STATUS_PATH', 'status/status.json'),
    ],
];
