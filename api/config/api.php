<?php

/*
 * The public API (docs/adr/020-public-api.md): API keys, rate limits,
 * idempotency, deprecations and the developer portal.
 */

return [
    'keys' => [
        // API-02: a key may be set to expire up to this many days ahead (null: never).
        'max_lifetime_days' => (int) env('API_KEY_MAX_LIFETIME_DAYS', 730),
        // API-02: the previous secret keeps working this many hours after a rotation (0 to 168).
        'default_overlap_hours' => 24,
        'max_overlap_hours' => 168,
        // Keys per tenant that are not revoked.
        'max_active' => (int) env('API_KEYS_MAX_ACTIVE', 50),
    ],

    // API-03: requests a minute. The tenant limit is the plan's
    // (catalogue_bundles.api_requests_per_minute) when it sets one.
    'rate_limits' => [
        'key_per_minute' => (int) env('API_RATE_KEY_PER_MINUTE', 120),
        'tenant_per_minute' => (int) env('API_RATE_TENANT_PER_MINUTE', 600),
        // Failed `sk_` authentications per client address before 429 (any key), and the window.
        'failed_auth_per_ip' => (int) env('API_FAILED_AUTH_PER_IP', 20),
        'failed_auth_decay_seconds' => 600,
        // Seconds a tenant's plan limit is cached.
        'plan_cache_seconds' => 300,
    ],

    'idempotency' => [
        // Hours a POST's first response is replayed for its Idempotency-Key.
        'hours' => 24,
        // Larger responses are not stored (the POST is then not idempotent).
        'max_body_bytes' => 1024 * 1024,
    ],

    // Usage chart and counters: days of history the back office shows.
    'usage_days' => 30,

    /*
     * Deprecated operations, "METHOD uri" (uri without the api/v1 prefix) =>
     * deprecated_at and sunset (ISO dates, sunset at least 6 months later)
     * and the changelog anchor. The `public-api` middleware adds the
     * Deprecation, Sunset and Link headers; the OpenAPI document marks the
     * operation deprecated; `api:docs --check` lets a removed operation
     * through only when it is listed here.
     */
    'deprecations' => [],

    // The developer portal's changelog (web app), linked from Deprecation headers.
    'changelog_url' => rtrim((string) env('FRONTEND_URL', 'http://localhost:3008'), '/').'/developers/changelog',
];
