<?php

// POS module settings (docs/modules/pos.md).
return [
    // NUM-02: numbers per device range, and the remainder below which a
    // device gets its next range when it asks.
    'ranges' => [
        'size' => (int) env('POS_RANGE_SIZE', 500),
        'threshold' => (int) env('POS_RANGE_THRESHOLD', 100),
    ],

    // NFR-04: a sale naming a shift the server never received waits
    // (shift_unknown, retryable) this many hours after it was sold; after
    // that it is stored on a placeholder shift, flagged shift_missing.
    'unknown_shift_grace_hours' => (int) env('POS_UNKNOWN_SHIFT_GRACE_HOURS', 72),
];
