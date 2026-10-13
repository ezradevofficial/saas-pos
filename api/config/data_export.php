<?php

// EXP-02: full tenant data exports (docs/adr/015-tenant-export.md).
return [
    // Exports an Owner may request per tenant in any 24 hours.
    'per_day' => (int) env('DATA_EXPORT_PER_DAY', 3),

    // A download link is minted on request (authenticated) and lasts minutes;
    // the zip is kept for days.
    'link_minutes' => (int) env('DATA_EXPORT_LINK_MINUTES', 5),
    'keep_days' => (int) env('DATA_EXPORT_KEEP_DAYS', 7),

    // Downloads of one export per hour (the `tenant-export-download` limiter).
    'downloads_per_hour' => (int) env('DATA_EXPORT_DOWNLOADS_PER_HOUR', 5),

    // Rows fetched per round trip from each table's cursor.
    'chunk' => (int) env('DATA_EXPORT_CHUNK', 1000),

    // The largest export built (tables and files, before compression); a
    // larger one fails with `too_large`. 5 GB.
    'max_bytes' => (int) env('DATA_EXPORT_MAX_BYTES', 5 * 1024 * 1024 * 1024),

    // The queue the build runs on. Horizon's `supervisor-exports` works it on
    // the `redis-exports` connection, whose retry_after exceeds the timeout.
    'queue' => env('DATA_EXPORT_QUEUE', 'exports'),
    'timeout' => (int) env('DATA_EXPORT_TIMEOUT', 3600),

    // A build queued this long found no worker (exports queue backed up or
    // not worked): it is failed so the Owner may ask again.
    'queued_stale_hours' => (int) env('DATA_EXPORT_QUEUED_STALE_HOURS', 24),
];
