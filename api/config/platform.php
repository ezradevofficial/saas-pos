<?php

// Platform staff and the plan catalogue (ADR 013, SUB-01, ONB-03).
return [
    // How long a staff token lasts after two-factor sign-in.
    'token_minutes' => (int) env('PLATFORM_TOKEN_MINUTES', 720),

    // Public catalogue and quote endpoints, per IP a minute.
    'public_per_minute' => (int) env('CATALOGUE_PUBLIC_PER_MINUTE', 60),
];
