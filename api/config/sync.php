<?php

// NFR-04, AUTH-06, AUTH-08: POS device sync and PIN settings (ADR 004).
return [
    // Rows per entity per pull: the default, and the most a device may ask for.
    'page_size' => 500,
    'max_page_size' => 1000,

    // Pulls, bootstraps and PIN calls per device per minute.
    'requests_per_minute' => 120,

    // Wrong PINs before the user's PIN is locked on that device (AUTH-06).
    'pin_max_attempts' => 5,

    // PBKDF2-HMAC-SHA256 iterations for the offline PIN and card keys (at
    // least 100,000). Stored per user, so a change applies to new PINs only.
    'pin_iterations' => max(100000, (int) env('POS_PIN_ITERATIONS', 150000)),

    // How long an online manager override token stays valid (AUTH-08).
    'override_ttl_seconds' => 120,
];
