<?php

// AUTH-11, ADR 023: enterprise single sign-on. Operator settings only: each
// tenant's connections (client ids, secrets, certificates) are tenant data.
return [
    // Where the IdPs send the browser back: {callback_base}/api/v1/auth/sso/...
    // (the OIDC redirect URI, SAML entity ids and ACS URLs). Defaults to APP_URL.
    'callback_base' => env('SSO_CALLBACK_BASE') ?: env('APP_URL', 'http://localhost:8008'),

    // The IdPs' base URLs. Production keeps the defaults; local development and
    // the Playwright check point them at a fake IdP.
    'entra' => [
        'authority' => env('SSO_ENTRA_AUTHORITY', 'https://login.microsoftonline.com'),
    ],
    'google' => [
        'issuer' => env('SSO_GOOGLE_ISSUER', 'https://accounts.google.com'),
    ],

    // How long a started sign-in waits for the IdP, and a ticket for the browser.
    'flow_seconds' => (int) env('SSO_FLOW_SECONDS', 600),
    'ticket_seconds' => (int) env('SSO_TICKET_SECONDS', 120),

    // POST auth/sso/start, per address a minute.
    'start_per_minute' => (int) env('SSO_START_PER_MINUTE', 20),

    // SAML metadata fetched by URL: at most this many bytes, within this many seconds.
    'metadata_max_bytes' => 1_048_576,
    'metadata_timeout' => 10,
];
