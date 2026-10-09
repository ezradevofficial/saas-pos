<?php

// POS module settings (docs/modules/pos.md).
return [
    // NUM-02: numbers per device range, and the remainder below which a
    // device gets its next range when it asks.
    'ranges' => [
        'size' => (int) env('POS_RANGE_SIZE', 500),
        'threshold' => (int) env('POS_RANGE_THRESHOLD', 100),
    ],
    // AUTH-07: records made more than this many hours after the sign-in they cite are flagged `session_stale`.
    'actor' => [
        'session_max_hours' => (int) env('POS_SESSION_MAX_HOURS', 24),
    ],
];
