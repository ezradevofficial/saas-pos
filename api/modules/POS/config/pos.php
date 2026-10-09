<?php

// POS module settings (docs/modules/pos.md).
return [
    // NUM-02: numbers per device range, and the remainder below which a
    // device gets its next range when it asks.
    'ranges' => [
        'size' => (int) env('POS_RANGE_SIZE', 500),
        'threshold' => (int) env('POS_RANGE_THRESHOLD', 100),
    ],
];
