<?php

// Platform staff and the plan catalogue (ADR 013, SUB-01, ONB-03).
return [
    // How long a staff token lasts after two-factor sign-in (8 hours).
    'token_minutes' => (int) env('PLATFORM_TOKEN_MINUTES', 480),

    // Where the platform console is served. In production, its own subdomain
    // (for example https://platform.example.com), apart from the tenant app's
    // origin, so a script on a tenant page can never reach a staff session.
    // Setup links point here. Defaults to FRONTEND_URL.
    'url' => env('PLATFORM_URL') ?: env('FRONTEND_URL', 'http://localhost:3008'),

    // Public catalogue and quote, per IP a minute.
    'public_per_minute' => (int) env('CATALOGUE_PUBLIC_PER_MINUTE', 60),

    // A quote with a coupon code (against guessing codes): per IP, and in all, a minute.
    'coupon_per_minute' => (int) env('CATALOGUE_COUPON_PER_MINUTE', 5),
    'coupon_global_per_minute' => (int) env('CATALOGUE_COUPON_GLOBAL_PER_MINUTE', 100),
];
