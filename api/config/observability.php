<?php

use App\Core\Support\TrustedProxies;

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

        // Review M1: a request that reached the API through the load balancer
        // or another trusted proxy came from the internet, so it is refused
        // whatever its token or forwarded address. The NodeBalancer's backend
        // range (192.168.255.0/24) is inside Linode's private network, so it is
        // always refused, as is every TRUSTED_PROXIES entry.
        'trusted_proxies' => TrustedProxies::parse(env('TRUSTED_PROXIES')),
        'refused_ranges' => ['192.168.255.0/24'],

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
        // Empty: the tenant files disk (MEDIA_DISK).
        'storage_disk' => env('HEALTH_STORAGE_DISK'),
        // Readiness is for the load balancer: a stopped Horizon does not take
        // the API out of rotation unless this is true (it still shows in
        // /metrics and the status snapshot).
        'require_horizon' => (bool) env('HEALTH_REQUIRE_HORIZON', false),

        // GET /health/ready answers from a cache this long (seconds; 0: never cached).
        'cache_seconds' => (int) env('HEALTH_CACHE_SECONDS', 5),
    ],

    'status' => [
        // `health:status` writes the public status snapshot here every minute.
        'disk' => env('STATUS_DISK', 'local'),
        'path' => env('STATUS_PATH', 'status/status.json'),
    ],

    // NFR-07, ADR 025: whether the host's database backups (WAL-G,
    // deploy/host) are on. Files live in Object Storage and are not copied
    // by the host. Set by the owner in .env once provisioning turned them
    // on; `app:preflight` warns in production while it is false.
    'backups' => [
        'enabled' => (bool) env('BACKUPS_ENABLED', false),
    ],
];
