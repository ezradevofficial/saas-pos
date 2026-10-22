<?php

/*
 * The marketplace (MKT-01..MKT-04, ADM-05; docs/adr/024-marketplace.md):
 * OAuth for apps (laravel/passport), paid apps and the first-party
 * connectors. Provider credentials are owner inputs: each connector says
 * "not configured" until its settings are present.
 */

return [
    'oauth' => [
        // Access tokens are short; refresh tokens rotate on every use.
        'access_token_minutes' => (int) env('MARKETPLACE_ACCESS_TOKEN_MINUTES', 60),
        'refresh_token_days' => (int) env('MARKETPLACE_REFRESH_TOKEN_DAYS', 30),
        // Token requests a minute (the token endpoint is public). Phase 8 review M5: counted per
        // client id, so one app refreshing for many tenants from one address is not refused
        // with the others behind that address, with a looser ceiling per address over all clients.
        'token_requests_per_minute' => (int) env('MARKETPLACE_TOKEN_REQUESTS_PER_MINUTE', 120),
        'token_requests_per_minute_per_ip' => (int) env('MARKETPLACE_TOKEN_REQUESTS_PER_MINUTE_PER_IP', 600),
    ],

    // MKT-03: the developer's share of a paid app line (net of discount and tax); the platform keeps the rest.
    'developer_share_percent' => 80,

    'apps' => [
        // Apps per developer that are not archived.
        'max_per_developer' => (int) env('MARKETPLACE_MAX_APPS_PER_DEVELOPER', 20),
        'max_redirect_uris' => 5,
        'max_scopes' => 60,
        // App logos: PNG, JPEG or WebP, square, up to this size.
        'logo_max_kb' => 512,
        'logo_disk' => env('MARKETPLACE_LOGO_DISK', env('FILESYSTEM_DISK', 'local')),
        // Hours the previous webhook signing secret keeps signing after a rotation.
        'webhook_rotation_overlap_hours' => 24,
    ],

    /*
     * MKT-04: first-party connectors, listed as apps of the platform's own
     * developer (seeded by migration). Each runs as a connector service
     * that is an OAuth client of the public API: its redirect URI, webhook
     * URL and provider credentials are owner inputs. Until they are set
     * the connector reports `not_configured` and cannot be installed.
     */
    'connectors' => [
        'courier' => [
            'app_slug' => 'courier-connector',
            'redirect_uri' => env('CONNECTOR_COURIER_REDIRECT_URI'),
            'webhook_url' => env('CONNECTOR_COURIER_WEBHOOK_URL'),
            // The courier's API (generic interface, Connectors\Courier): `none` until the owner chooses a provider.
            'driver' => env('CONNECTOR_COURIER_DRIVER', 'none'),
        ],
        'messaging' => [
            'app_slug' => 'sms-whatsapp-connector',
            'redirect_uri' => env('CONNECTOR_MESSAGING_REDIRECT_URI'),
            'webhook_url' => env('CONNECTOR_MESSAGING_WEBHOOK_URL'),
            // Sends through the notifier's SMS and WhatsApp drivers (config/notifications.php).
        ],
        'bi' => [
            'app_slug' => 'power-bi-excel-connector',
            'redirect_uri' => env('CONNECTOR_BI_REDIRECT_URI'),
            'webhook_url' => null,
            // Reads the public list endpoints as CSV feeds (`?format=csv`), with scopes and field rules.
        ],
        'biometric' => [
            'app_slug' => 'biometric-attendance-connector',
            'redirect_uri' => env('CONNECTOR_BIOMETRIC_REDIRECT_URI'),
            'webhook_url' => null,
            // Uses the HR biometric attendance device API (HR-24).
        ],
    ],
];
