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

    // AUTH-08: permissions that approve overrides at the till. Their holders
    // need a 6-digit PIN.
    'override_permissions' => ['pos.sale.void', 'pos.sale.refund', 'pos.price.override', 'pos.discount.give'],

    // AUTH-06: the permission that lets a user sign in at a till (StaffDirectory).
    'sign_in_permission' => 'pos.till.sign_in',

    // Offline overrides: how far ahead of the server clock authorised_at may be.
    'override_clock_skew_seconds' => 600,

    // NFR-05: snapshot entities are rebuilt at most this often per device
    // (0: every pull). PIN, lock and secret changes take effect at once.
    'snapshot_ttl_seconds' => (int) env('SYNC_SNAPSHOT_TTL', 30),

    // Rotations of a device secret (challenge, rotate, activate) per device per hour.
    'secret_rotations_per_hour' => 5,

    // NFR-04: sync lag (the oldest running transaction holding back every
    // device's changes) above this is logged as a warning.
    'lag_warning_seconds' => 120,
];
