<?php

// EXP-02: full tenant data exports (docs/adr/015-tenant-export.md).
return [
    // Exports an Owner may request per tenant in any 24 hours.
    'per_day' => (int) env('DATA_EXPORT_PER_DAY', 3),

    // How long a download link is valid, and how long the zip is kept.
    'link_hours' => (int) env('DATA_EXPORT_LINK_HOURS', 24),
    'keep_days' => (int) env('DATA_EXPORT_KEEP_DAYS', 7),

    // Rows read per query while streaming a table.
    'chunk' => (int) env('DATA_EXPORT_CHUNK', 1000),

    // The queue the build runs on. Horizon's `supervisor-exports` works it on
    // the `redis-exports` connection, whose retry_after exceeds the timeout.
    'queue' => env('DATA_EXPORT_QUEUE', 'exports'),
    'timeout' => (int) env('DATA_EXPORT_TIMEOUT', 3600),
];
