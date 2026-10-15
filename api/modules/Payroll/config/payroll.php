<?php

return [
    // CP-01, PAY-07: where the payroll packs are read from (`{dir}/{CODE}/payroll.json`).
    // The shipped packs have no figures; a local or staging environment may point this
    // at a directory of test packs (never committed) to try runs end to end.
    'pack_dir' => env('PAYROLL_PACK_DIR'),
];
