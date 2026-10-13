<?php

// IMP-01..IMP-03: imports (docs/adr/014-imports.md).
return [
    // The queue dry runs, imports and undos run on. Horizon's
    // `supervisor-imports` works it on the `redis-imports` connection, whose
    // retry_after exceeds the timeout, so a running import is never handed
    // to a second worker.
    'queue' => env('IMPORT_QUEUE', 'imports'),
    'timeout' => (int) env('IMPORT_TIMEOUT', 3600),

    // An undo commits this many rows at a time, each chunk under its own
    // audit batch, so the tenant's audit chain is never held for long.
    'undo_chunk' => (int) env('IMPORT_UNDO_CHUNK', 500),
];
