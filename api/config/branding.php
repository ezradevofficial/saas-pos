<?php

// BR-04..BR-07: tenant hosts, custom domains and branded email.
return [
    // BR-04: tenants are reached at {slug}.{base_domain} (e.g. acme.example.app).
    // Null: subdomains are off; only verified custom domains are branded.
    'base_domain' => env('APP_BASE_DOMAIN'),

    'domains' => [
        // BR-05: the TXT record a tenant creates to prove a domain is theirs:
        // {txt_prefix}.{host} with the value {txt_value_prefix}{token}.
        'txt_prefix' => env('BRANDING_TXT_PREFIX', '_platform-verify'),
        'txt_value_prefix' => env('BRANDING_TXT_VALUE_PREFIX', 'platform-verify='),
        // A domain still unproven after this many days is marked failed.
        'pending_days' => (int) env('BRANDING_DOMAIN_PENDING_DAYS', 3),
        // Where the tenant points the domain (a CNAME target), shown in the UI.
        'cname_target' => env('BRANDING_CNAME_TARGET'),
        // GET tls/ask (Caddy on-demand TLS): requests a minute per address.
        'tls_ask_per_minute' => (int) env('BRANDING_TLS_ASK_PER_MINUTE', 120),
    ],

    // BR-04: GET public/branding, before sign-in: requests a minute per address.
    'public_per_minute' => (int) env('BRANDING_PUBLIC_PER_MINUTE', 60),

    'mail' => [
        // BR-06: SPF guidance: the include the tenant adds to their SPF record
        // so the platform's mail provider may send for their domain.
        'spf_include' => env('BRANDING_SPF_INCLUDE'),
        // BR-06: DKIM guidance: the selector and the CNAME target of the
        // provider's key (per sending domain, set up with the provider).
        'dkim_selector' => env('BRANDING_DKIM_SELECTOR'),
        'dkim_target' => env('BRANDING_DKIM_TARGET'),
    ],
];
