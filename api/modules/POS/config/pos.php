<?php

// POS module settings (docs/modules/pos.md).
return [
    // NUM-02: numbers per device range, and the remainder below which a
    // device gets its next range when it asks.
    'ranges' => [
        'size' => (int) env('POS_RANGE_SIZE', 500),
        'threshold' => (int) env('POS_RANGE_THRESHOLD', 100),
        // A yearly format's next-year range is given this many days before the year ends (offline New Year).
        'next_period_days' => (int) env('POS_RANGE_NEXT_PERIOD_DAYS', 14),
    ],
    // AUTH-07: records made more than this many hours after the sign-in they cite are flagged `session_stale`.
    'actor' => [
        'session_max_hours' => (int) env('POS_SESSION_MAX_HOURS', 24),
    ],
];
